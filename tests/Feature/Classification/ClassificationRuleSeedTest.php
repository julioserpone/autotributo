<?php

declare(strict_types=1);

use App\Domain\Classification\Actions\ClassifyFiscalDocumentItems;
use App\Domain\Classification\Actions\SyncClassificationRules;
use App\Domain\Classification\Actions\SyncExpenseCategories;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Models\ExpenseCategory;
use App\Domain\Classification\Services\ClassificationRuleRepository;
use App\Domain\Companies\Models\Company;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\FiscalDocuments\Models\FiscalDocumentItem;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'rules']);
    $this->context = app(TenantContext::class);
    $this->context->initialize($this->tenant);
    Cache::flush();

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '0990000018001',
    ]);

    app(SyncExpenseCategories::class)->handle();
});

afterEach(function (): void {
    $this->context->end();
});

it('carga el árbol de categorías con sus subcategorías', function (): void {
    $parents = ExpenseCategory::query()->whereNull('parent_id')->count();
    $children = ExpenseCategory::query()->whereNotNull('parent_id')->count();

    expect($parents)->toBeGreaterThanOrEqual(16)
        ->and($children)->toBeGreaterThanOrEqual(18)
        ->and(ExpenseCategory::query()->where('code', 'alimentacion_supermercado')->exists())->toBeTrue();
});

it('es idempotente al recargar categorías', function (): void {
    $before = ExpenseCategory::query()->count();

    app(SyncExpenseCategories::class)->handle();

    expect(ExpenseCategory::query()->count())->toBe($before);
});

it('crea las reglas base inactivas y sin duplicarlas al recargar', function (): void {
    // Otros casos de esta y de otras suites pueden haber dejado reglas base.
    DB::table('classification_rules')->delete();
    ClassificationRuleRepository::flush();

    $first = app(SyncClassificationRules::class)->handle();
    $second = app(SyncClassificationRules::class)->handle();

    expect($first['created'])->toBe(14)
        ->and($second['created'])->toBe(0)
        ->and($second['updated'])->toBe(0)
        ->and($second['unchanged'])->toBe(14)
        ->and(ClassificationRule::query()->withoutGlobalScopes()->count())->toBe(14)
        ->and(ClassificationRule::query()->withoutGlobalScopes()->where('is_active', true)->count())->toBe(0);
});

it('respeta la decisión del usuario al recargar reglas', function (): void {
    app(SyncClassificationRules::class)->handle();

    $rule = ClassificationRule::query()->withoutGlobalScopes()->firstOrFail();
    $rule->is_active = true;
    $rule->save();

    app(SyncClassificationRules::class)->handle();

    expect($rule->fresh()?->is_active)->toBeTrue();
});

it('clasifica por RUC del emisor cuando la regla está activa', function (): void {
    app(SyncClassificationRules::class)->handle();

    ClassificationRule::query()
        ->withoutGlobalScopes()
        ->where('pattern', '1768154260001')
        ->firstOrFail()
        ->update(['is_active' => true]);

    Cache::flush();

    $document = FiscalDocument::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'issuer_ruc' => '1768154260001',
        'issuer_name' => 'EMPRESA METROPOLITANA DE AGUA POTABLE',
        'totals_mismatch' => false,
    ]);

    FiscalDocumentItem::query()->create([
        'tenant_id' => $this->tenant->id,
        'fiscal_document_id' => $document->id,
        'line_number' => 1,
        'description' => 'Agua Potable',
        'normalized_description' => 'AGUA POTABLE',
        'quantity' => 1,
        'unit_price' => 10,
        'discount' => 0,
        'subtotal_cents' => 1_000,
        'total_cents' => 1_000,
        'classification_status' => FiscalDocumentItem::STATUS_PENDING,
    ]);

    $classified = app(ClassifyFiscalDocumentItems::class)->handle($document);

    $item = $document->items()->firstOrFail();

    expect($classified)->toBe(1)
        ->and($item->classification_status)->toBe(FiscalDocumentItem::STATUS_AUTO)
        ->and($item->expense_category_id)->not->toBeNull();
});

it('no clasifica por RUC si la regla sigue inactiva', function (): void {
    app(SyncClassificationRules::class)->handle();

    $document = FiscalDocument::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'issuer_ruc' => '1768154260001',
        'totals_mismatch' => false,
    ]);

    FiscalDocumentItem::query()->create([
        'tenant_id' => $this->tenant->id,
        'fiscal_document_id' => $document->id,
        'line_number' => 1,
        'description' => 'Agua Potable',
        'normalized_description' => 'AGUA POTABLE',
        'quantity' => 1,
        'unit_price' => 10,
        'discount' => 0,
        'subtotal_cents' => 1_000,
        'total_cents' => 1_000,
        'classification_status' => FiscalDocumentItem::STATUS_PENDING,
    ]);

    app(ClassifyFiscalDocumentItems::class)->handle($document);

    expect($document->items()->firstOrFail()->expense_category_id)->toBeNull();
});

it('el comando activa y desactiva reglas por RUC', function (): void {
    DB::table('classification_rules')->delete();
    ClassificationRuleRepository::flush();
    Cache::flush();

    $this->artisan('classification:seed-rules', ['--activate' => true])->assertExitCode(0);

    expect(ClassificationRule::query()->withoutGlobalScopes()->where('is_active', true)->count())->toBeGreaterThanOrEqual(14);

    $this->artisan('classification:seed-rules', ['--deactivate' => true, '--ruc' => '1768154260001'])->assertExitCode(0);

    expect(ClassificationRule::query()->withoutGlobalScopes()->where('pattern', '1768154260001')->where('is_active', true)->count())->toBe(0);
});

it('invalida la caché de reglas al activar una', function (): void {
    DB::table('classification_rules')->delete();
    ClassificationRuleRepository::flush();
    Cache::flush();

    app(SyncClassificationRules::class)->handle();

    $this->artisan('classification:seed-rules', ['--activate' => true])->assertExitCode(0);

    // Sin invalidar la caché, el motor seguiría viendo la lista anterior.
    $rules = app(ClassificationRuleRepository::class)->forTenant((int) $this->tenant->id);

    expect(count($rules))->toBeGreaterThanOrEqual(14);
});
