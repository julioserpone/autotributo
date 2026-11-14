<?php

declare(strict_types=1);

use App\Domain\Classification\Actions\SyncExpenseCategories;
use App\Domain\Classification\Enums\MatchType;
use App\Domain\Classification\Models\Classification;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Models\ExpenseCategory;
use App\Domain\Classification\Services\ClassificationRuleRepository;
use App\Domain\Companies\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function rulePayload(int $categoryId, array $overrides = []): array
{
    return [
        'name' => 'Alimentación',
        'priority' => 100,
        'match_type' => MatchType::Contains->value,
        'pattern' => 'SUPERMERCADO',
        'expense_category_id' => $categoryId,
        ...$overrides,
    ];
}

/**
 * Inserta una regla global sin pasar por el modelo: fuera de un tenant el
 * `creating` del trait rellenaría `tenant_id`, y las reglas globales solo las
 * carga el catálogo base.
 *
 * @param  array<string, mixed>  $overrides
 */
function insertGlobalRule(int $categoryId, array $overrides = []): int
{
    return (int) DB::table('classification_rules')->insertGetId([
        'tenant_id' => null,
        'name' => 'Regla global',
        'priority' => 100,
        'match_type' => MatchType::Contains->value,
        'pattern' => 'GLOBAL',
        'expense_category_id' => $categoryId,
        'is_active' => true,
        'hits_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);
}

function classificationItem(Invoice $invoice, int $line, string $description): InvoiceItem
{
    return InvoiceItem::query()->create([
        'tenant_id' => $invoice->tenant_id,
        'invoice_id' => $invoice->id,
        'line_number' => $line,
        'description' => $description,
        'normalized_description' => mb_strtoupper($description),
    ]);
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'rules-test']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->makeUser = function (MembershipRole $role, ?Tenant $tenant = null): User {
        $tenant ??= $this->tenant;

        app(TenantContext::class)->initialize($tenant);

        $user = User::factory()->create();

        $roleModel = Role::firstOrCreate([
            'name' => $role->value,
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);

        $user->assignRole($roleModel);

        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role->value,
        ]);

        return $user;
    };

    $this->owner = ($this->makeUser)(MembershipRole::Owner);
    $this->viewer = ($this->makeUser)(MembershipRole::Viewer);

    app(SyncExpenseCategories::class)->handle();

    $this->categoryId = (int) ExpenseCategory::query()->where('code', 'alimentacion')->value('id');
});

it('crea una regla del tenant y la devuelve con su categoría', function (): void {
    $response = $this->actingAs($this->owner)
        ->postJson('/api/v1/classification-rules', rulePayload($this->categoryId))
        ->assertCreated()
        ->assertJsonPath('data.name', 'Alimentación')
        ->assertJsonPath('data.match_type', 'contains')
        ->assertJsonPath('data.is_global', false)
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.expense_category.code', 'alimentacion');

    expect($response->json('data.match_type_label'))->toBe('La descripción contiene')
        ->and(ClassificationRule::query()->count())->toBe(1);
});

it('lista reglas del tenant y globales ordenadas por prioridad', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/classification-rules', rulePayload($this->categoryId, ['priority' => 20]))
        ->assertCreated();

    insertGlobalRule($this->categoryId, ['priority' => 10, 'pattern' => 'PAN']);

    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/classification-rules')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.priority', 10)
        ->assertJsonPath('data.0.is_global', true)
        ->assertJsonPath('data.1.priority', 20)
        ->assertJsonPath('data.1.is_global', false);

    expect($response->json('data.0.expense_category.code'))->toBe('alimentacion');
});

it('filtra reglas por tipo, estado y texto', function (): void {
    $this->actingAs($this->owner)->postJson('/api/v1/classification-rules', rulePayload($this->categoryId))->assertCreated();
    $this->actingAs($this->owner)->postJson(
        '/api/v1/classification-rules',
        rulePayload($this->categoryId, [
            'name' => 'Código',
            'match_type' => MatchType::ItemCode->value,
            'pattern' => 'SRV-001',
            'is_active' => false,
        ]),
    )->assertCreated();

    $this->actingAs($this->owner)
        ->getJson('/api/v1/classification-rules?match_type=item_code')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.pattern', 'SRV-001');

    $this->actingAs($this->owner)
        ->getJson('/api/v1/classification-rules?is_active=0')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Código');

    $this->actingAs($this->owner)
        ->getJson('/api/v1/classification-rules?search=SUPER')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.name', 'Alimentación');
});

it('actualiza y elimina una regla del tenant', function (): void {
    $id = $this->actingAs($this->owner)
        ->postJson('/api/v1/classification-rules', rulePayload($this->categoryId))
        ->json('data.id');

    $this->actingAs($this->owner)
        ->putJson('/api/v1/classification-rules/'.$id, [
            'name' => 'Alimentación ajustada',
            'priority' => 5,
            'is_active' => false,
        ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Alimentación ajustada')
        ->assertJsonPath('data.priority', 5)
        ->assertJsonPath('data.is_active', false);

    $this->actingAs($this->owner)
        ->deleteJson('/api/v1/classification-rules/'.$id)
        ->assertNoContent();

    expect(ClassificationRule::query()->count())->toBe(0)
        ->and(ClassificationRule::withTrashed()->count())->toBe(1);

    $this->actingAs($this->owner)
        ->getJson('/api/v1/classification-rules/'.$id)
        ->assertNotFound();
});

it('expone las reglas globales en solo lectura', function (): void {
    $id = insertGlobalRule($this->categoryId);

    $this->actingAs($this->owner)
        ->getJson('/api/v1/classification-rules/'.$id)
        ->assertOk()
        ->assertJsonPath('data.is_global', true);

    $this->actingAs($this->owner)
        ->putJson('/api/v1/classification-rules/'.$id, ['name' => 'Cambio'])
        ->assertForbidden();

    $this->actingAs($this->owner)
        ->deleteJson('/api/v1/classification-rules/'.$id)
        ->assertForbidden();
});

it('rechaza reglas que chocan con otra de la misma prioridad', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/classification-rules', rulePayload($this->categoryId, [
            'pattern' => 'PAN INTEGRAL',
        ]))
        ->assertCreated();

    $payload = rulePayload($this->categoryId, ['pattern' => 'PAN']);

    $this->actingAs($this->owner)
        ->postJson('/api/v1/classification-rules', $payload)
        ->assertStatus(409)
        ->assertJsonPath('type', 'https://autotributo.com/problems/classification-rule-conflict')
        ->assertJsonPath('conflicts.0.pattern', 'PAN INTEGRAL');

    expect(ClassificationRule::query()->count())->toBe(1);

    $this->actingAs($this->owner)
        ->postJson('/api/v1/classification-rules', [...$payload, 'confirm_conflict' => true])
        ->assertCreated();

    expect(ClassificationRule::query()->count())->toBe(2);
});

it('no considera conflicto una regla con prioridad distinta', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/classification-rules', rulePayload($this->categoryId, [
            'pattern' => 'PAN INTEGRAL',
            'priority' => 10,
        ]))
        ->assertCreated();

    $this->actingAs($this->owner)
        ->postJson('/api/v1/classification-rules', rulePayload($this->categoryId, [
            'pattern' => 'PAN',
            'priority' => 20,
        ]))
        ->assertCreated();
});

it('valida la expresión regular antes de guardar', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/classification-rules', rulePayload($this->categoryId, [
            'match_type' => MatchType::Regex->value,
            'pattern' => '/[a-/',
        ]))
        ->assertStatus(422)
        ->assertJsonPath('errors.pattern.0', 'La expresión regular no es válida.');

    expect(ClassificationRule::query()->count())->toBe(0);
});

it('valida que la categoría de gasto exista', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/classification-rules', rulePayload(999_999))
        ->assertStatus(422)
        ->assertJsonPath('errors.expense_category_id.0', 'La categoría de gasto no existe.');
});

it('hace un dry-run sin guardar la regla ni clasificar ítems', function (): void {
    $invoice = Invoice::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
    ]);

    classificationItem($invoice, 1, 'PAN INTEGRAL');
    classificationItem($invoice, 2, 'SERVICIOS CONTABLES');

    $this->actingAs($this->owner)
        ->postJson('/api/v1/classification-rules/dry-run', [
            'name' => 'Pan',
            'match_type' => MatchType::Contains->value,
            'pattern' => 'PAN',
            'priority' => 50,
        ])
        ->assertOk()
        ->assertJsonPath('data.matches_count', 1)
        ->assertJsonPath('data.scanned_count', 2)
        ->assertJsonPath('data.sample.0.description', 'PAN INTEGRAL');

    expect(ClassificationRule::query()->count())->toBe(0)
        ->and(Classification::query()->count())->toBe(0);
});

it('acepta el dry-run de una regla ya guardada', function (): void {
    $invoice = Invoice::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
    ]);

    classificationItem($invoice, 1, 'PAN INTEGRAL');

    $id = $this->actingAs($this->owner)
        ->postJson('/api/v1/classification-rules', rulePayload($this->categoryId, [
            'pattern' => 'PAN',
        ]))
        ->json('data.id');

    $this->actingAs($this->owner)
        ->postJson('/api/v1/classification-rules/dry-run', ['rule_id' => $id])
        ->assertOk()
        ->assertJsonPath('data.matches_count', 1);
});

it('invalida la caché del catálogo al guardar o desactivar reglas', function (): void {
    $repository = app(ClassificationRuleRepository::class);

    expect($repository->forTenant($this->tenant->id))->toBe([]);

    $id = $this->actingAs($this->owner)
        ->postJson('/api/v1/classification-rules', rulePayload($this->categoryId))
        ->json('data.id');

    expect($repository->forTenant($this->tenant->id))->toHaveCount(1);

    $this->actingAs($this->owner)
        ->putJson('/api/v1/classification-rules/'.$id, ['is_active' => false])
        ->assertOk();

    expect($repository->forTenant($this->tenant->id))->toBe([]);
});

it('oculta las reglas de otro tenant a un owner', function (): void {
    $other = Tenant::factory()->create(['slug' => 'rules-other']);

    app(TenantContext::class)->initialize($other);

    $foreign = ClassificationRule::query()->create([
        'tenant_id' => $other->id,
        'name' => 'Regla ajena',
        'priority' => 100,
        'match_type' => MatchType::Contains,
        'pattern' => 'AJENA',
        'expense_category_id' => $this->categoryId,
        'is_active' => true,
    ]);

    app(TenantContext::class)->initialize($this->tenant);

    $this->actingAs($this->owner)
        ->getJson('/api/v1/classification-rules/'.$foreign->id)
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->putJson('/api/v1/classification-rules/'.$foreign->id, ['name' => 'Intruso'])
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->deleteJson('/api/v1/classification-rules/'.$foreign->id)
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->getJson('/api/v1/classification-rules')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('reserva la gestión de reglas a los roles con rules.manage', function (): void {
    $this->actingAs($this->viewer)
        ->getJson('/api/v1/classification-rules')
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->postJson('/api/v1/classification-rules', rulePayload($this->categoryId))
        ->assertForbidden();

    expect(ClassificationRule::query()->count())->toBe(0);
});

it('expone el catálogo de categorías de gasto', function (): void {
    $this->actingAs($this->viewer)
        ->getJson('/api/v1/expense-categories')
        ->assertOk()
        ->assertJsonCount(9, 'data')
        ->assertJsonPath('data.0.code', 'alimentacion')
        ->assertJsonPath('data.0.is_personal_deductible', true)
        ->assertJsonPath('data.0.is_business_deductible', false);
});

it('exige autenticación para reglas y categorías', function (): void {
    $this->getJson('/api/v1/classification-rules')->assertUnauthorized();
    $this->getJson('/api/v1/expense-categories')->assertUnauthorized();
});
