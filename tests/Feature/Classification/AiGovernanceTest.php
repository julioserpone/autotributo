<?php

declare(strict_types=1);

use App\Domain\Classification\Actions\ClassifyFiscalDocumentItems;
use App\Domain\Classification\Actions\SyncExpenseCategories;
use App\Domain\Classification\Contracts\Classifier;
use App\Domain\Classification\Data\ClassificationSuggestion;
use App\Domain\Classification\Data\ClassifierResult;
use App\Domain\Classification\Data\LlmCallRecord;
use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Classification\Enums\LlmCallStatus;
use App\Domain\Classification\Exceptions\ClassifierUnavailable;
use App\Domain\Classification\Exceptions\InvalidClassifierOutput;
use App\Domain\Classification\Models\Classification;
use App\Domain\Classification\Models\ExpenseCategory;
use App\Domain\Classification\Models\LlmCall;
use App\Domain\Classification\Services\LlmCallRecorder;
use App\Domain\Companies\Models\Company;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\FiscalDocuments\Models\FiscalDocumentItem;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Integrations\Ai\FakeClassifier;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 */
function governanceFiscalDocument(int $tenantId, int $companyId, array $overrides = []): FiscalDocument
{
    return FiscalDocument::factory()->create([
        'tenant_id' => $tenantId,
        'company_id' => $companyId,
        ...$overrides,
    ]);
}

function governanceItem(FiscalDocument $fiscal_document, int $line, string $description): FiscalDocumentItem
{
    return FiscalDocumentItem::query()->create([
        'tenant_id' => $fiscal_document->tenant_id,
        'fiscal_document_id' => $fiscal_document->id,
        'line_number' => $line,
        'description' => $description,
        'normalized_description' => mb_strtoupper($description),
    ]);
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'ai-governance-test']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    app(SyncExpenseCategories::class)->handle();

    $this->fake = app(FakeClassifier::class);
    $this->classify = app(ClassifyFiscalDocumentItems::class);
});

it('registra la llamada con modelo, prompt, tokens, coste y latencia', function (): void {
    $fiscal_document = governanceFiscalDocument($this->tenant->id, $this->company->id);
    governanceItem($fiscal_document, 1, 'SERVICIOS DE CONSULTORIA CONTABLE');

    $this->fake->push(new ClassifierResult(
        suggestion: new ClassificationSuggestion('operativo', 0.95, 'Servicios profesionales'),
        model: 'gpt-4.1-mini',
        promptVersion: 'v1',
        inputTokens: 1_000,
        outputTokens: 500,
        latencyMs: 42,
        provider: 'openai',
    ));

    expect($this->classify->handle($fiscal_document))->toBe(1);

    $call = LlmCall::query()->sole();
    $classification = Classification::query()->sole();

    expect($call->provider)->toBe('openai')
        ->and($call->model)->toBe('gpt-4.1-mini')
        ->and($call->purpose)->toBe(LlmCallRecorder::PURPOSE_CLASSIFY_ITEM)
        ->and($call->prompt_version)->toBe('v1')
        ->and($call->input_tokens)->toBe(1_000)
        ->and($call->output_tokens)->toBe(500)
        ->and($call->latency_ms)->toBe(42)
        ->and($call->cost_micros)->toBe(1_200)
        ->and($call->status)->toBe(LlmCallStatus::Success)
        ->and($call->error_code)->toBeNull()
        ->and($call->input_hash)->toHaveLength(64)
        ->and($classification->llm_call_id)->toBe($call->id)
        ->and($classification->method)->toBe(ClassificationMethod::Llm);
});

it('reutiliza la caché sin volver a llamar al proveedor', function (): void {
    $first = governanceFiscalDocument($this->tenant->id, $this->company->id);
    $firstItem = governanceItem($first, 1, 'SERVICIOS DE CONSULTORIA CONTABLE');

    $this->fake->pushSuggestion('operativo', 0.95, 'Servicios profesionales');

    expect($this->classify->handle($first))->toBe(1);

    $original = LlmCall::query()->sole();

    $second = governanceFiscalDocument($this->tenant->id, $this->company->id);
    $secondItem = governanceItem($second, 1, 'SERVICIOS DE CONSULTORIA CONTABLE');

    expect($this->classify->handle($second))->toBe(1)
        ->and($this->fake->requests)->toHaveCount(1)
        ->and(LlmCall::query()->count())->toBe(1);

    $cached = Classification::query()
        ->where('fiscal_document_item_id', $secondItem->id)
        ->sole();

    expect($firstItem->refresh()->classification_status)->toBe(FiscalDocumentItem::STATUS_AUTO)
        ->and($secondItem->refresh()->classification_status)->toBe(FiscalDocumentItem::STATUS_AUTO)
        ->and($cached->method)->toBe(ClassificationMethod::Cache)
        ->and($cached->llm_call_id)->toBe($original->id)
        ->and($cached->confidence)->toBe(0.95)
        ->and($cached->prompt_version)->toBe('v1');
});

it('no reutiliza la caché cuando cambia la descripción', function (): void {
    $first = governanceFiscalDocument($this->tenant->id, $this->company->id);
    governanceItem($first, 1, 'SERVICIOS DE CONSULTORIA CONTABLE');

    $this->fake->pushSuggestion('operativo', 0.95)
        ->pushSuggestion('alimentacion', 0.90);

    $this->classify->handle($first);

    $second = governanceFiscalDocument($this->tenant->id, $this->company->id);
    governanceItem($second, 1, 'ALMUERZO DE TRABAJO');

    expect($this->classify->handle($second))->toBe(1)
        ->and($this->fake->requests)->toHaveCount(2)
        ->and(LlmCall::query()->count())->toBe(2);
});

it('se puede desactivar la caché por configuración', function (): void {
    config(['ai.cache.enabled' => false]);
    app()->forgetInstance(Classifier::class);
    app()->forgetInstance(ClassifyFiscalDocumentItems::class);
    $classify = app(ClassifyFiscalDocumentItems::class);

    $first = governanceFiscalDocument($this->tenant->id, $this->company->id);
    governanceItem($first, 1, 'SERVICIOS DE CONSULTORIA CONTABLE');

    $this->fake->pushSuggestion('operativo', 0.95)
        ->pushSuggestion('operativo', 0.95);

    $classify->handle($first);

    $second = governanceFiscalDocument($this->tenant->id, $this->company->id);
    governanceItem($second, 1, 'SERVICIOS DE CONSULTORIA CONTABLE');

    expect($classify->handle($second))->toBe(1)
        ->and($this->fake->requests)->toHaveCount(2)
        ->and(LlmCall::query()->count())->toBe(2);
});

it('corta la clasificación automática cuando el presupuesto está agotado', function (): void {
    config(['ai.budget.monthly_usd' => 0.001]);
    app()->forgetInstance(Classifier::class);
    app()->forgetInstance(ClassifyFiscalDocumentItems::class);
    $classify = app(ClassifyFiscalDocumentItems::class);

    app(LlmCallRecorder::class)->record(new LlmCallRecord(
        tenantId: (int) $this->tenant->id,
        purpose: LlmCallRecorder::PURPOSE_CLASSIFY_ITEM,
        inputHash: str_repeat('a', 64),
        provider: 'fake',
        model: 'gpt-4.1-mini',
        promptVersion: 'v1',
        status: LlmCallStatus::Success,
        costMicros: 1_000,
    ));

    $fiscal_document = governanceFiscalDocument($this->tenant->id, $this->company->id);
    $item = governanceItem($fiscal_document, 1, 'SERVICIOS DE CONSULTORIA CONTABLE');

    $this->fake->pushSuggestion('operativo', 0.95);

    expect($classify->handle($fiscal_document))->toBe(0)
        ->and($this->fake->requests)->toBeEmpty()
        ->and($item->refresh()->classification_status)->toBe(FiscalDocumentItem::STATUS_REVIEW)
        ->and($item->expense_category_id)->toBeNull()
        ->and(Classification::query()->count())->toBe(0);

    $cut = LlmCall::query()->where('status', LlmCallStatus::BudgetExceeded->value)->sole();

    expect($cut->error_code)->toBe('budget_exceeded')
        ->and($cut->cost_micros)->toBe(0)
        ->and($cut->input_tokens)->toBe(0);
});

it('solo suma al presupuesto el coste del mes en curso', function (): void {
    config(['ai.budget.monthly_usd' => 0.001]);
    app()->forgetInstance(Classifier::class);
    app()->forgetInstance(ClassifyFiscalDocumentItems::class);
    $classify = app(ClassifyFiscalDocumentItems::class);

    $recorder = app(LlmCallRecorder::class);

    $previousMonth = $recorder->record(new LlmCallRecord(
        tenantId: (int) $this->tenant->id,
        purpose: LlmCallRecorder::PURPOSE_CLASSIFY_ITEM,
        inputHash: str_repeat('b', 64),
        provider: 'fake',
        model: 'gpt-4.1-mini',
        promptVersion: 'v1',
        status: LlmCallStatus::Success,
        costMicros: 900,
    ));
    $previousMonth->forceFill(['created_at' => now()->subMonthNoOverflow()])->save();

    $fiscal_document = governanceFiscalDocument($this->tenant->id, $this->company->id);
    governanceItem($fiscal_document, 1, 'SERVICIOS DE CONSULTORIA CONTABLE');

    $this->fake->pushSuggestion('operativo', 0.95);

    expect($classify->handle($fiscal_document))->toBe(1)
        ->and($this->fake->requests)->toHaveCount(1)
        ->and(LlmCall::query()->where('status', LlmCallStatus::Success->value)->count())->toBe(2);
});

it('registra el motivo cuando el proveedor no está disponible', function (): void {
    $fiscal_document = governanceFiscalDocument($this->tenant->id, $this->company->id);
    $item = governanceItem($fiscal_document, 1, 'SERVICIOS DE CONSULTORIA CONTABLE');

    $this->fake->pushException(ClassifierUnavailable::rateLimited());

    expect($this->classify->handle($fiscal_document))->toBe(0);

    $call = LlmCall::query()->sole();

    expect($call->status)->toBe(LlmCallStatus::Unavailable)
        ->and($call->error_code)->toBe('rate_limited')
        ->and($call->provider)->toBe('fake')
        ->and($call->cost_micros)->toBe(0)
        ->and($item->refresh()->classification_status)->toBe(FiscalDocumentItem::STATUS_REVIEW);
});

it('registra la salida inválida tras agotar el reintento', function (): void {
    $fiscal_document = governanceFiscalDocument($this->tenant->id, $this->company->id);
    governanceItem($fiscal_document, 1, 'SERVICIOS DE CONSULTORIA CONTABLE');

    $this->fake->pushException(InvalidClassifierOutput::malformedJson())
        ->pushException(InvalidClassifierOutput::unexpectedShape());

    expect($this->classify->handle($fiscal_document))->toBe(0);

    $call = LlmCall::query()->sole();

    expect($call->status)->toBe(LlmCallStatus::InvalidOutput)
        ->and($call->error_code)->toBe('unexpected_shape')
        ->and($this->fake->requests)->toHaveCount(2);
});

it('mantiene la caché y el presupuesto separados entre tenants', function (): void {
    $first = governanceFiscalDocument($this->tenant->id, $this->company->id);
    governanceItem($first, 1, 'SERVICIOS DE CONSULTORIA CONTABLE');

    $this->fake->pushSuggestion('operativo', 0.95);

    $this->classify->handle($first);

    $otherTenant = Tenant::factory()->create(['slug' => 'ai-governance-other']);
    app(TenantContext::class)->initialize($otherTenant);

    $otherCompany = Company::factory()->create(['tenant_id' => $otherTenant->id]);
    $otherFiscalDocument = governanceFiscalDocument($otherTenant->id, $otherCompany->id);
    $otherItem = governanceItem($otherFiscalDocument, 1, 'SERVICIOS DE CONSULTORIA CONTABLE');

    $this->fake->pushSuggestion('alimentacion', 0.90);

    expect($this->classify->handle($otherFiscalDocument))->toBe(1)
        ->and($this->fake->requests)->toHaveCount(2)
        ->and(LlmCall::withoutGlobalScopes()->count())->toBe(2)
        ->and($otherItem->refresh()->expense_category_id)
        ->toBe((int) ExpenseCategory::query()
            ->where('code', 'alimentacion')
            ->value('id'));
});
