<?php

declare(strict_types=1);

use App\Domain\Classification\Actions\ClassifyInvoiceItems;
use App\Domain\Classification\Actions\SyncExpenseCategories;
use App\Domain\Classification\Contracts\Classifier;
use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Enums\MatchType;
use App\Domain\Classification\Exceptions\ClassifierUnavailable;
use App\Domain\Classification\Exceptions\InvalidClassifierOutput;
use App\Domain\Classification\Models\Classification;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Models\ExpenseCategory;
use App\Domain\Companies\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Integrations\Ai\FakeClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function aiClassifyInvoice(int $tenantId, int $companyId, array $overrides = []): Invoice
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
function aiClassifyItem(Invoice $invoice, int $line, string $description, array $overrides = []): InvoiceItem
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
    $this->tenant = Tenant::factory()->create(['slug' => 'ai-classification-test']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    app(SyncExpenseCategories::class)->handle();

    $this->fake = app(FakeClassifier::class);
    $this->classify = app(ClassifyInvoiceItems::class);
    $this->categoryId = fn (string $code): int => (int) ExpenseCategory::query()
        ->where('code', $code)
        ->value('id');
});

it('acepta automáticamente una sugerencia con confianza suficiente', function (): void {
    $invoice = aiClassifyInvoice($this->tenant->id, $this->company->id);
    $item = aiClassifyItem($invoice, 1, 'SERVICIOS DE CONSULTORIA CONTABLE');

    $this->fake->pushSuggestion('operativo', 0.95, 'Servicios profesionales');

    expect($this->classify->handle($invoice))->toBe(1);

    $item->refresh();
    $classification = Classification::query()->firstOrFail();

    expect($item->expense_category_id)->toBe(($this->categoryId)('operativo'))
        ->and($item->classification_status)->toBe(InvoiceItem::STATUS_AUTO)
        ->and($classification->method)->toBe(ClassificationMethod::Llm)
        ->and($classification->status)->toBe(ClassificationStatus::Suggested)
        ->and($classification->confidence)->toBe(0.95)
        ->and($classification->prompt_version)->toBe('v1')
        ->and($classification->rule_id)->toBeNull()
        ->and($this->fake->requests)->toHaveCount(1)
        ->and($this->fake->requests[0]->description)->toBe('SERVICIOS DE CONSULTORIA CONTABLE')
        ->and($this->fake->requests[0]->issuerActivity)->toBeNull()
        ->and($this->fake->requests[0]->categoryCodes())->toContain('operativo');
});

it('envía a revisión una sugerencia por debajo del umbral', function (): void {
    $invoice = aiClassifyInvoice($this->tenant->id, $this->company->id);
    $item = aiClassifyItem($invoice, 1, 'GASTO POCO CLARO');

    $this->fake->pushSuggestion('operativo', 0.40, 'No es concluyente');

    expect($this->classify->handle($invoice))->toBe(1);

    $item->refresh();
    $classification = Classification::query()->firstOrFail();

    expect($item->classification_status)->toBe(InvoiceItem::STATUS_REVIEW)
        ->and($item->expense_category_id)->toBe(($this->categoryId)('operativo'))
        ->and($classification->status)->toBe(ClassificationStatus::NeedsReview)
        ->and($classification->method)->toBe(ClassificationMethod::Llm)
        ->and($classification->confidence)->toBe(0.40);
});

it('mantiene pendiente el ítem cuando el clasificador no sugiere nada', function (): void {
    $invoice = aiClassifyInvoice($this->tenant->id, $this->company->id);
    $item = aiClassifyItem($invoice, 1, 'SIN SUGERENCIA');

    expect($this->classify->handle($invoice))->toBe(0)
        ->and($item->refresh()->classification_status)->toBe(InvoiceItem::STATUS_PENDING)
        ->and($item->expense_category_id)->toBeNull()
        ->and(Classification::query()->count())->toBe(0)
        ->and($this->fake->requests)->toHaveCount(1);
});

it('no llama a la IA cuando una regla clasifica el ítem', function (): void {
    $invoice = aiClassifyInvoice($this->tenant->id, $this->company->id);
    $item = aiClassifyItem($invoice, 1, 'SERVICIOS DE CONSULTORIA CONTABLE');

    ClassificationRule::query()->create([
        'name' => 'Consultoría',
        'priority' => 10,
        'match_type' => MatchType::Contains,
        'pattern' => 'CONSULTORIA',
        'expense_category_id' => ($this->categoryId)('operativo'),
        'is_active' => true,
    ]);

    $this->fake->pushSuggestion('alimentacion', 0.99);

    expect($this->classify->handle($invoice))->toBe(1);

    $item->refresh();
    $classification = Classification::query()->firstOrFail();

    expect($item->expense_category_id)->toBe(($this->categoryId)('operativo'))
        ->and($classification->method)->toBe(ClassificationMethod::Rule)
        ->and($this->fake->requests)->toBeEmpty();
});

it('degrada a revisión sin persistir clasificación cuando la IA no está disponible', function (): void {
    $invoice = aiClassifyInvoice($this->tenant->id, $this->company->id);
    $item = aiClassifyItem($invoice, 1, 'SERVICIO PROFESIONAL');

    $this->fake->pushException(ClassifierUnavailable::rateLimited());

    expect($this->classify->handle($invoice))->toBe(0);

    expect($item->refresh()->classification_status)->toBe(InvoiceItem::STATUS_REVIEW)
        ->and($item->expense_category_id)->toBeNull()
        ->and(Classification::query()->count())->toBe(0)
        ->and($this->fake->requests)->toHaveCount(1);
});

it('degrada a revisión ante un error inesperado del adaptador', function (): void {
    $invoice = aiClassifyInvoice($this->tenant->id, $this->company->id);
    $item = aiClassifyItem($invoice, 1, 'SERVICIO PROFESIONAL');

    $this->fake->pushException(new RuntimeException('fallo inesperado del adaptador'));

    expect($this->classify->handle($invoice))->toBe(0)
        ->and($item->refresh()->classification_status)->toBe(InvoiceItem::STATUS_REVIEW)
        ->and(Classification::query()->count())->toBe(0)
        ->and($this->fake->requests)->toHaveCount(1);
});

it('reintenta una salida inválida y clasifica con la segunda respuesta', function (): void {
    $invoice = aiClassifyInvoice($this->tenant->id, $this->company->id);
    $item = aiClassifyItem($invoice, 1, 'SERVICIO PROFESIONAL');

    $this->fake->pushException(InvalidClassifierOutput::malformedJson())
        ->pushSuggestion('operativo', 0.90);

    expect($this->classify->handle($invoice))->toBe(1)
        ->and($item->refresh()->classification_status)->toBe(InvoiceItem::STATUS_AUTO)
        ->and($this->fake->requests)->toHaveCount(2);
});

it('degrada a revisión si la IA devuelve dos salidas inválidas', function (): void {
    $invoice = aiClassifyInvoice($this->tenant->id, $this->company->id);
    $item = aiClassifyItem($invoice, 1, 'SERVICIO PROFESIONAL');

    $this->fake->pushException(InvalidClassifierOutput::malformedJson())
        ->pushException(InvalidClassifierOutput::unexpectedShape());

    expect($this->classify->handle($invoice))->toBe(0)
        ->and($item->refresh()->classification_status)->toBe(InvoiceItem::STATUS_REVIEW)
        ->and(Classification::query()->count())->toBe(0)
        ->and($this->fake->requests)->toHaveCount(2);
});

it('abre el circuito y deja en revisión los ítems siguientes', function (): void {
    config(['ai.classifier.circuit.failure_threshold' => 1]);
    app()->forgetInstance(Classifier::class);
    app()->forgetInstance(ClassifyInvoiceItems::class);
    $classify = app(ClassifyInvoiceItems::class);

    $invoice = aiClassifyInvoice($this->tenant->id, $this->company->id);
    $first = aiClassifyItem($invoice, 1, 'PRIMER SERVICIO');
    $second = aiClassifyItem($invoice, 2, 'SEGUNDO SERVICIO');

    $this->fake->pushException(ClassifierUnavailable::rateLimited())
        ->pushSuggestion('operativo', 0.99);

    expect($classify->handle($invoice))->toBe(0)
        ->and($first->refresh()->classification_status)->toBe(InvoiceItem::STATUS_REVIEW)
        ->and($second->refresh()->classification_status)->toBe(InvoiceItem::STATUS_REVIEW)
        ->and($this->fake->requests)->toHaveCount(1);
});

it('no vuelve a consultar la IA para un ítem ya clasificado', function (): void {
    $invoice = aiClassifyInvoice($this->tenant->id, $this->company->id);
    $item = aiClassifyItem($invoice, 1, 'SERVICIO PROFESIONAL');

    $this->fake->pushSuggestion('operativo', 0.95);

    $this->classify->handle($invoice);

    expect($this->classify->handle($invoice))->toBe(0)
        ->and($this->fake->requests)->toHaveCount(1)
        ->and(Classification::query()->where('invoice_item_id', $item->id)->count())->toBe(1);
});

it('mantiene los circuitos separados entre tenants', function (): void {
    config(['ai.classifier.circuit.failure_threshold' => 1]);
    app()->forgetInstance(Classifier::class);
    app()->forgetInstance(ClassifyInvoiceItems::class);
    $classify = app(ClassifyInvoiceItems::class);

    $invoiceA = aiClassifyInvoice($this->tenant->id, $this->company->id);
    aiClassifyItem($invoiceA, 1, 'SERVICIO DEL TENANT A');

    $this->fake->pushException(ClassifierUnavailable::rateLimited());

    expect($classify->handle($invoiceA))->toBe(0);

    $otherTenant = Tenant::factory()->create(['slug' => 'ai-classification-other']);
    app(TenantContext::class)->initialize($otherTenant);

    $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
    $invoiceB = aiClassifyInvoice($otherTenant->id, $otherCompany->id);
    $itemB = aiClassifyItem($invoiceB, 1, 'SERVICIO DEL TENANT B');

    $this->fake->pushSuggestion('operativo', 0.95);

    expect($classify->handle($invoiceB))->toBe(1)
        ->and($itemB->refresh()->classification_status)->toBe(InvoiceItem::STATUS_AUTO)
        ->and($this->fake->requests)->toHaveCount(2);
});
