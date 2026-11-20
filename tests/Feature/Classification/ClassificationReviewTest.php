<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Classification\Actions\SyncExpenseCategories;
use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Models\Classification;
use App\Domain\Classification\Models\ExpenseCategory;
use App\Domain\Companies\Models\Company;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\FiscalDocuments\Models\FiscalDocumentItem;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function reviewedItem(FiscalDocument $fiscal_document, int $line, string $description, array $overrides = []): FiscalDocumentItem
{
    return FiscalDocumentItem::query()->create([
        'tenant_id' => $fiscal_document->tenant_id,
        'fiscal_document_id' => $fiscal_document->id,
        'line_number' => $line,
        'description' => $description,
        'normalized_description' => mb_strtoupper($description),
        'subtotal_cents' => 10_000,
        'total_cents' => 10_000,
        ...$overrides,
    ]);
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'review-test']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->makeUser = function (MembershipRole $role, ?Tenant $tenant = null): User {
        $tenant ??= $this->tenant;

        app(TenantContext::class)->initialize($tenant);

        $user = User::factory()->create();

        $user->assignRole(Role::firstOrCreate([
            'name' => $role->value,
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]));

        return $user;
    };

    $this->owner = ($this->makeUser)(MembershipRole::Owner);
    $this->assistant = ($this->makeUser)(MembershipRole::Assistant);
    $this->viewer = ($this->makeUser)(MembershipRole::Viewer);

    app(TenantContext::class)->initialize($this->tenant);

    app(SyncExpenseCategories::class)->handle();

    $this->foodCategoryId = (int) ExpenseCategory::query()->where('code', 'alimentacion')->value('id');
    $this->transportCategoryId = (int) ExpenseCategory::query()->where('code', 'operativo')->value('id');

    $this->fiscal_document = FiscalDocument::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'issuer_name' => 'FERRETERÍA CENTRAL S.A.',
    ]);

    /**
     * Crea un ítem y su clasificación vigente.
     */
    $this->makeClassification = function (
        ClassificationStatus $status,
        int $categoryId,
        ClassificationMethod $method = ClassificationMethod::Llm,
        float $confidence = 0.72,
        int $line = 1,
    ): Classification {
        $item = reviewedItem(
            $this->fiscal_document,
            $line,
            "ÍTEM {$line}",
            ['expense_category_id' => $categoryId],
        );

        return Classification::query()->create([
            'tenant_id' => $this->tenant->id,
            'fiscal_document_item_id' => $item->id,
            'expense_category_id' => $categoryId,
            'method' => $method,
            'confidence' => $confidence,
            'status' => $status,
        ]);
    };
});

it('lista la cola con needs_review primero y filtra por estado y método', function (): void {
    ($this->makeClassification)(ClassificationStatus::Accepted, $this->foodCategoryId, line: 1);
    ($this->makeClassification)(ClassificationStatus::NeedsReview, $this->transportCategoryId, line: 2);
    ($this->makeClassification)(ClassificationStatus::Suggested, $this->foodCategoryId, line: 3);

    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/classifications')
        ->assertOk()
        ->assertJsonCount(3, 'data')
        ->assertJsonPath('data.0.status', 'needs_review')
        ->assertJsonPath('data.1.status', 'suggested')
        ->assertJsonPath('data.2.status', 'accepted');

    expect($response->json('data.0.item.description'))->toBe('ÍTEM 2')
        ->and($response->json('data.0.fiscal_document.issuer_name'))->toBe('FERRETERÍA CENTRAL S.A.')
        ->and($response->json('data.0.expense_category.code'))->toBe('operativo');

    $this->actingAs($this->owner)
        ->getJson('/api/v1/classifications?filter[status]=needs_review')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.status', 'needs_review');

    $this->actingAs($this->owner)
        ->getJson('/api/v1/classifications?'.http_build_query(['filter' => ['search' => 'ÍTEM 1']]))
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.item.line_number', 1);
});

it('acepta la categoría sugerida, firma la revisión y deja el ítem manual', function (): void {
    $classification = ($this->makeClassification)(ClassificationStatus::NeedsReview, $this->foodCategoryId);
    $itemId = $classification->fiscal_document_item_id;

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/classifications/{$classification->id}", ['decision' => 'accept'])
        ->assertOk()
        ->assertJsonPath('data.status', 'accepted')
        ->assertJsonPath('data.expense_category.code', 'alimentacion')
        ->assertJsonPath('data.reviewed_by.id', $this->owner->id)
        ->assertJsonPath('data.method', 'llm')
        ->assertJsonPath('data.confidence', 0.72);

    $fresh = $classification->fresh();
    $item = FiscalDocumentItem::query()->findOrFail($itemId);

    expect($fresh->status)->toBe(ClassificationStatus::Accepted)
        ->and($fresh->reviewed_by)->toBe($this->owner->id)
        ->and($fresh->reviewed_at)->not->toBeNull()
        ->and($fresh->method)->toBe(ClassificationMethod::Llm)
        ->and($item->expense_category_id)->toBe($this->foodCategoryId)
        ->and($item->classification_status)->toBe(FiscalDocumentItem::STATUS_MANUAL);

    expect(AuditLog::query()
        ->where('auditable_type', Classification::class)
        ->where('auditable_id', $classification->id)
        ->where('event', 'updated')
        ->exists())->toBeTrue();
});

it('acepta con otra categoría y corrige la del ítem', function (): void {
    $classification = ($this->makeClassification)(ClassificationStatus::NeedsReview, $this->foodCategoryId);

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/classifications/{$classification->id}", [
            'decision' => 'accept',
            'expense_category_id' => $this->transportCategoryId,
        ])
        ->assertOk()
        ->assertJsonPath('data.expense_category.code', 'operativo');

    expect($classification->fresh()->expense_category_id)->toBe($this->transportCategoryId)
        ->and(FiscalDocumentItem::query()->findOrFail($classification->fiscal_document_item_id)->expense_category_id)
        ->toBe($this->transportCategoryId);
});

it('rechaza la clasificación y devuelve el ítem a pendiente sin categoría', function (): void {
    $classification = ($this->makeClassification)(ClassificationStatus::NeedsReview, $this->foodCategoryId);

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/classifications/{$classification->id}", ['decision' => 'reject'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');

    $item = FiscalDocumentItem::query()->findOrFail($classification->fiscal_document_item_id);

    expect($classification->fresh()->expense_category_id)->toBe($this->foodCategoryId)
        ->and($item->expense_category_id)->toBeNull()
        ->and($item->classification_status)->toBe(FiscalDocumentItem::STATUS_PENDING);
});

it('permite corregir una decisión ya tomada', function (): void {
    $classification = ($this->makeClassification)(ClassificationStatus::Accepted, $this->foodCategoryId);

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/classifications/{$classification->id}", ['decision' => 'reject'])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected');
});

it('revisa en bloque y reporta los ids que no existen en el tenant', function (): void {
    $first = ($this->makeClassification)(ClassificationStatus::NeedsReview, $this->foodCategoryId, line: 1);
    $second = ($this->makeClassification)(ClassificationStatus::NeedsReview, $this->foodCategoryId, line: 2);

    $otherTenant = Tenant::factory()->create(['slug' => 'review-other']);

    app(TenantContext::class)->initialize($otherTenant);
    $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherFiscalDocument = FiscalDocument::factory()->create([
        'tenant_id' => $otherTenant->id,
        'company_id' => $otherCompany->id,
    ]);
    $otherItem = reviewedItem($otherFiscalDocument, 1, 'AJENO');
    $otherClassification = Classification::query()->create([
        'tenant_id' => $otherTenant->id,
        'fiscal_document_item_id' => $otherItem->id,
        'expense_category_id' => $this->foodCategoryId,
        'method' => ClassificationMethod::Llm,
        'confidence' => 0.4,
        'status' => ClassificationStatus::NeedsReview,
    ]);

    app(TenantContext::class)->initialize($this->tenant);

    $response = $this->actingAs($this->owner)
        ->postJson('/api/v1/classifications/bulk-review', [
            'ids' => [$first->id, $second->id, $otherClassification->id, 999_999],
            'decision' => 'accept',
            'expense_category_id' => $this->transportCategoryId,
        ])
        ->assertOk()
        ->assertJsonPath('data.updated', 2)
        ->assertJsonCount(2, 'data.failed');

    expect($response->json('data.failed.0'))->toBe(['id' => $otherClassification->id, 'reason' => 'not_found'])
        ->and($response->json('data.failed.1'))->toBe(['id' => 999_999, 'reason' => 'not_found'])
        ->and($first->fresh()->status)->toBe(ClassificationStatus::Accepted)
        ->and($second->fresh()->status)->toBe(ClassificationStatus::Accepted)
        ->and($first->fresh()->expense_category_id)->toBe($this->transportCategoryId)
        ->and($otherClassification->fresh()->status)->toBe(ClassificationStatus::NeedsReview);
});

it('limita el lote a cien clasificaciones', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/classifications/bulk-review', [
            'ids' => range(1, 101),
            'decision' => 'accept',
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('ids');
});

it('valida la decisión y la categoría', function (): void {
    $classification = ($this->makeClassification)(ClassificationStatus::NeedsReview, $this->foodCategoryId);

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/classifications/{$classification->id}", [])
        ->assertStatus(422)
        ->assertJsonValidationErrors('decision');

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/classifications/{$classification->id}", [
            'decision' => 'reject',
            'expense_category_id' => $this->transportCategoryId,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('expense_category_id');

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/classifications/{$classification->id}", [
            'decision' => 'accept',
            'expense_category_id' => 999_999,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('expense_category_id');
});

it('rechaza una categoría que no está vigente', function (): void {
    $expired = ExpenseCategory::query()->create([
        'code' => 'categoria_vencida',
        'name' => 'Categoría vencida',
        'display_order' => 99,
        'source' => 'test',
        'valid_from' => '2020-01-01',
        'valid_to' => '2020-12-31',
    ]);

    $classification = ($this->makeClassification)(ClassificationStatus::NeedsReview, $this->foodCategoryId);

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/classifications/{$classification->id}", [
            'decision' => 'accept',
            'expense_category_id' => $expired->id,
        ])
        ->assertStatus(422)
        ->assertJsonValidationErrors('expense_category_id');
});

it('permite consultar a un viewer pero no revisar', function (): void {
    $classification = ($this->makeClassification)(ClassificationStatus::NeedsReview, $this->foodCategoryId);

    $this->actingAs($this->viewer)
        ->getJson('/api/v1/classifications')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($this->viewer)
        ->patchJson("/api/v1/classifications/{$classification->id}", ['decision' => 'accept'])
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->postJson('/api/v1/classifications/bulk-review', [
            'ids' => [$classification->id],
            'decision' => 'accept',
        ])
        ->assertForbidden();
});

it('permite revisar a un assistant', function (): void {
    $classification = ($this->makeClassification)(ClassificationStatus::NeedsReview, $this->foodCategoryId);

    $this->actingAs($this->assistant)
        ->patchJson("/api/v1/classifications/{$classification->id}", ['decision' => 'accept'])
        ->assertOk()
        ->assertJsonPath('data.reviewed_by.id', $this->assistant->id);
});

it('no expone clasificaciones de otro tenant', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'review-isolation']);

    app(TenantContext::class)->initialize($otherTenant);
    $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherFiscalDocument = FiscalDocument::factory()->create([
        'tenant_id' => $otherTenant->id,
        'company_id' => $otherCompany->id,
    ]);
    $otherItem = reviewedItem($otherFiscalDocument, 1, 'AJENO');
    $otherClassification = Classification::query()->create([
        'tenant_id' => $otherTenant->id,
        'fiscal_document_item_id' => $otherItem->id,
        'expense_category_id' => $this->foodCategoryId,
        'method' => ClassificationMethod::Llm,
        'confidence' => 0.4,
        'status' => ClassificationStatus::NeedsReview,
    ]);

    app(TenantContext::class)->initialize($this->tenant);

    $this->actingAs($this->owner)
        ->getJson('/api/v1/classifications')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($this->owner)
        ->patchJson("/api/v1/classifications/{$otherClassification->id}", ['decision' => 'accept'])
        ->assertNotFound();
});
