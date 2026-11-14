<?php

declare(strict_types=1);

use App\Domain\Classification\Actions\ClassifyInvoiceItems;
use App\Domain\Classification\Actions\SyncExpenseCategories;
use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Enums\MatchType;
use App\Domain\Classification\Models\Classification;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Models\ExpenseCategory;
use App\Domain\Companies\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Sri\Actions\PersistSriInvoice;
use App\Domain\Sri\Contracts\SriInvoiceParser;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Support\Sri\AccessKeyFactory;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function classifyRule(MatchType $type, string $pattern, int $categoryId, array $overrides = []): ClassificationRule
{
    return ClassificationRule::query()->create([
        'name' => 'Regla '.$pattern,
        'priority' => 100,
        'match_type' => $type,
        'pattern' => $pattern,
        'expense_category_id' => $categoryId,
        'is_active' => true,
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function classifyGlobalRule(MatchType $type, string $pattern, int $categoryId, array $overrides = []): int
{
    return (int) DB::table('classification_rules')->insertGetId([
        'tenant_id' => null,
        'name' => 'Global '.$pattern,
        'priority' => 100,
        'match_type' => $type->value,
        'pattern' => $pattern,
        'expense_category_id' => $categoryId,
        'is_active' => true,
        'hits_count' => 0,
        'created_at' => now(),
        'updated_at' => now(),
        ...$overrides,
    ]);
}

function classifyInvoice(int $tenantId, int $companyId, array $overrides = []): Invoice
{
    return Invoice::factory()->create([
        'tenant_id' => $tenantId,
        'company_id' => $companyId,
        ...$overrides,
    ]);
}

/**
 * @param  array<string, mixed>  $overrides
 */
function classifyItem(Invoice $invoice, int $line, string $description, array $overrides = []): InvoiceItem
{
    return InvoiceItem::query()->create([
        'tenant_id' => $invoice->tenant_id,
        'invoice_id' => $invoice->id,
        'line_number' => $line,
        'description' => $description,
        'normalized_description' => mb_strtoupper($description),
        ...$overrides,
    ]);
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'classification-test']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    app(SyncExpenseCategories::class)->handle();

    $this->category = fn (string $code): int => (int) ExpenseCategory::query()
        ->where('code', $code)
        ->value('id');

    $this->classify = app(ClassifyInvoiceItems::class);
});

it('clasifica un ítem con la primera regla que coincide', function (): void {
    $invoice = classifyInvoice($this->tenant->id, $this->company->id);
    $item = classifyItem($invoice, 1, 'Servicios de consultoría contable');

    $rule = classifyRule(MatchType::Contains, 'CONSULTORIA', ($this->category)('operativo'));

    expect($this->classify->handle($invoice))->toBe(1);

    $item->refresh();
    $rule->refresh();

    $classification = Classification::query()->firstOrFail();

    expect($item->expense_category_id)->toBe(($this->category)('operativo'))
        ->and($item->classification_status)->toBe(InvoiceItem::STATUS_AUTO)
        ->and($classification->method)->toBe(ClassificationMethod::Rule)
        ->and($classification->confidence)->toBe(1.0)
        ->and($classification->status)->toBe(ClassificationStatus::Suggested)
        ->and($classification->rule_id)->toBe($rule->id)
        ->and($rule->hits_count)->toBe(1)
        ->and($rule->last_matched_at)->not->toBeNull();
});

it('deja pendientes los ítems cuando no hay reglas', function (): void {
    $invoice = classifyInvoice($this->tenant->id, $this->company->id);
    $item = classifyItem($invoice, 1, 'PAN INTEGRAL');

    expect($this->classify->handle($invoice))->toBe(0)
        ->and($item->refresh()->classification_status)->toBe(InvoiceItem::STATUS_PENDING)
        ->and($item->expense_category_id)->toBeNull()
        ->and(Classification::query()->count())->toBe(0);
});

it('deja pendientes los ítems que no coinciden con ninguna regla', function (): void {
    $invoice = classifyInvoice($this->tenant->id, $this->company->id);
    $item = classifyItem($invoice, 1, 'SERVICIOS CONTABLES');

    classifyRule(MatchType::Contains, 'PAN', ($this->category)('alimentacion'));

    expect($this->classify->handle($invoice))->toBe(0)
        ->and($item->refresh()->classification_status)->toBe(InvoiceItem::STATUS_PENDING);
});

it('clasifica por código de ítem y por RUC del proveedor', function (): void {
    $invoice = classifyInvoice($this->tenant->id, $this->company->id, ['issuer_ruc' => '1758871873001']);

    $item = classifyItem($invoice, 1, 'Servicio genérico', ['main_code' => 'SRV-001']);

    classifyRule(MatchType::ItemCode, 'SRV-001', ($this->category)('operativo'));
    classifyRule(MatchType::ProviderRuc, '1758871873001', ($this->category)('no_deducible'));

    expect($this->classify->handle($invoice))->toBe(1)
        ->and($item->refresh()->expense_category_id)->toBe(($this->category)('operativo'));
});

it('respeta la prioridad y el desempate a favor del tenant', function (): void {
    $invoice = classifyInvoice($this->tenant->id, $this->company->id);
    $item = classifyItem($invoice, 1, 'PAN INTEGRAL');

    classifyGlobalRule(MatchType::Contains, 'PAN', ($this->category)('no_deducible'), ['priority' => 10]);
    classifyRule(MatchType::Contains, 'PAN', ($this->category)('alimentacion'), ['priority' => 50]);

    expect($this->classify->handle($invoice))->toBe(1)
        ->and($item->refresh()->expense_category_id)->toBe(($this->category)('no_deducible'));

    // Con la misma prioridad gana la regla del tenant sobre la global.
    ClassificationRule::query()->get()->each(fn (ClassificationRule $rule) => $rule->update(['is_active' => false]));

    classifyGlobalRule(MatchType::Contains, 'PAN INTEGRAL', ($this->category)('no_deducible'), ['priority' => 60]);
    classifyRule(MatchType::Contains, 'PAN INTEGRAL', ($this->category)('alimentacion'), ['priority' => 60]);

    $second = classifyItem(classifyInvoice($this->tenant->id, $this->company->id), 2, 'PAN INTEGRAL');
    $secondInvoice = $second->invoice;

    expect($this->classify->handle($secondInvoice))->toBe(1)
        ->and($second->refresh()->expense_category_id)->toBe(($this->category)('alimentacion'));
});

it('clasifica solo los ítems coincidentes de un comprobante', function (): void {
    $invoice = classifyInvoice($this->tenant->id, $this->company->id);

    $matching = classifyItem($invoice, 1, 'PAN INTEGRAL');
    $other = classifyItem($invoice, 2, 'SERVICIOS CONTABLES');

    classifyRule(MatchType::Contains, 'PAN', ($this->category)('alimentacion'));

    expect($this->classify->handle($invoice))->toBe(1)
        ->and($matching->refresh()->classification_status)->toBe(InvoiceItem::STATUS_AUTO)
        ->and($other->refresh()->classification_status)->toBe(InvoiceItem::STATUS_PENDING)
        ->and(Classification::query()->count())->toBe(1);
});

it('es idempotente en la clasificación del mismo ítem', function (): void {
    $invoice = classifyInvoice($this->tenant->id, $this->company->id);
    $item = classifyItem($invoice, 1, 'PAN INTEGRAL');

    classifyRule(MatchType::Contains, 'PAN', ($this->category)('alimentacion'));

    $this->classify->handle($invoice);
    $this->classify->handle($invoice);

    expect(Classification::query()->where('invoice_item_id', $item->id)->count())->toBe(1)
        ->and($invoice->refresh()->items()->count())->toBe(1);
});

it('clasifica automáticamente al persistir un comprobante del SRI', function (): void {
    $key = AccessKeyFactory::make();

    $xml = str_replace('{{CLAVE}}', $key, (string) file_get_contents(
        __DIR__.'/../../Fixtures/xml/invoice/factura.xml',
    ));

    $categoryId = ($this->category)('operativo');

    classifyRule(MatchType::Contains, 'CONSULTORIA', $categoryId);

    $parser = app(SriInvoiceParser::class);

    $result = app(PersistSriInvoice::class)->handle(
        $this->company,
        $parser->parse($xml),
        $xml,
    );

    $items = $result->invoice->items()->orderBy('line_number')->get();
    $classification = Classification::query()->firstOrFail();

    expect($items->first()->classification_status)->toBe(InvoiceItem::STATUS_AUTO)
        ->and($items->first()->expense_category_id)->toBe($categoryId)
        ->and($items->last()->classification_status)->toBe(InvoiceItem::STATUS_PENDING)
        ->and($classification->method)->toBe(ClassificationMethod::Rule)
        ->and($classification->invoice_item_id)->toBe($items->first()->id);
});
