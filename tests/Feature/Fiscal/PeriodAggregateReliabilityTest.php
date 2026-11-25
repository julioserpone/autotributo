<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Actions\ComputePeriodAggregates;
use App\Domain\Fiscal\Models\FiscalPeriod;
use App\Domain\Fiscal\Models\PeriodAggregate;
use App\Domain\Fiscal\Models\TaxRate;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\FiscalDocuments\Models\FiscalDocumentTax;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

/**
 * F5-07: el recálculo de agregados no puede perder datos en silencio.
 *
 * El diagnóstico del 2026-11-25 encontró `SQLSTATE[23505]` en
 * `period_aggregates_unique_bucket`: la agrupación incluía `rate` pero el
 * índice único no, y un mismo `codigoPorcentaje` llegaba con tarifa 0 (XML sin
 * `<tarifa>` de cabecera) y con la tarifa del catálogo. La transacción se
 * revertía completa y el período quedaba sin agregados con el run `completed`.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->initialize($this->tenant);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '1790016919001',
    ]);

    TaxRate::query()->create([
        'code' => 'IVA',
        'label' => 'IVA 15%',
        'sri_percentage_code' => '4',
        'rate' => 15,
        'valid_from' => '2024-04-01',
        'valid_to' => null,
        'legal_basis' => 'Ley Orgánica para el Fortalecimiento de la Economía',
        'source' => 'test',
    ]);
});

afterEach(function (): void {
    app(TenantContext::class)->end();
});

/**
 * Crea una factura recibida con un impuesto de cabecera.
 */
function f507WithTax(string $issueDate, string $percentageCode, string $rate, int $base, int $tax, string $accessKey): FiscalDocument
{
    $document = FiscalDocument::factory()->create([
        'tenant_id' => test()->tenant->id,
        'company_id' => test()->company->id,
        'access_key' => $accessKey,
        'issue_date' => $issueDate,
        'direction' => FiscalDocument::DIRECTION_RECEIVED,
        'subtotal_cents' => $base,
        'tax_cents' => $tax,
        'total_cents' => $base + $tax,
    ]);

    FiscalDocumentTax::create([
        'tenant_id' => test()->tenant->id,
        'fiscal_document_id' => $document->id,
        'tax_code' => '2',
        'sri_percentage_code' => $percentageCode,
        'rate' => $rate,
        'base_cents' => $base,
        'amount_cents' => $tax,
    ]);

    return $document;
}

it('no falla cuando el mismo codigoPorcentaje llega con tarifa 0 y con la del catálogo', function (): void {
    // Un XML del SRI no publica <tarifa> en la cabecera: llega en 0.
    f507WithTax('2026-10-05', '4', '0', 5_464, 820, str_repeat('1', 49));
    f507WithTax('2026-10-06', '4', '15', 1_731, 259, str_repeat('2', 49));

    $period = app(ComputePeriodAggregates::class)->handle($this->company, 2026, 10);

    $rows = PeriodAggregate::query()
        ->where('fiscal_period_id', $period->id)
        ->where('direction', 'purchases')
        ->where('sri_percentage_code', '4')
        ->get();

    expect($period->aggregation_status)->toBe(FiscalPeriod::AGGREGATION_COMPLETED)
        ->and($rows)->toHaveCount(1)
        ->and((float) $rows->first()->rate)->toBe(15.0)
        ->and($rows->first()->base_cents)->toBe(5_464 + 1_731)
        ->and($rows->first()->tax_cents)->toBe(820 + 259)
        ->and($rows->first()->documents_count)->toBe(2);
});

it('resuelve la tarifa del catálogo por codigoPorcentaje y conserva los decimales', function (): void {
    f507WithTax('2026-10-07', '4', '0', 10_000, 1_500, str_repeat('3', 49));

    $period = app(ComputePeriodAggregates::class)->handle($this->company, 2026, 10);

    $row = PeriodAggregate::query()->where('fiscal_period_id', $period->id)->firstOrFail();

    expect($row->sri_percentage_code)->toBe('4')
        ->and((float) $row->rate)->toBe(15.0)
        ->and($row->base_cents)->toBe(10_000);
});

it('registra el estado de agregación del período', function (): void {
    f507WithTax('2026-09-10', '4', '15', 1_000, 150, str_repeat('4', 49));

    $compute = app(ComputePeriodAggregates::class);

    $period = $compute->handle($this->company, 2026, 9);

    expect($period->aggregation_status)->toBe(FiscalPeriod::AGGREGATION_COMPLETED)
        ->and($period->aggregation_error)->toBeNull()
        ->and($period->aggregation_attempts)->toBe(1)
        ->and($period->aggregated_at)->not->toBeNull();

    // Repetir es idempotente pero suma intentos: se ve que se recalculó.
    $again = $compute->handle($this->company, 2026, 9);

    expect($again->aggregation_attempts)->toBe(2)
        ->and(PeriodAggregate::query()->where('fiscal_period_id', $period->id)->count())->toBe(1);
});

it('deja el período sin comprobantes en completed y no en failed', function (): void {
    $period = app(ComputePeriodAggregates::class)->handle($this->company, 2026, 2);

    expect($period->aggregation_status)->toBe(FiscalPeriod::AGGREGATION_COMPLETED)
        ->and(PeriodAggregate::query()->where('fiscal_period_id', $period->id)->count())->toBe(0);
});

it('attempt no propaga el fallo y lo deja visible en el período', function (): void {
    $compute = app(ComputePeriodAggregates::class);

    expect($compute->attempt($this->company, 2026, 8))->toBeTrue();

    $period = FiscalPeriod::query()
        ->where('company_id', $this->company->id)
        ->where('year', 2026)
        ->where('month', 8)
        ->firstOrFail();

    expect($period->aggregation_status)->toBe(FiscalPeriod::AGGREGATION_COMPLETED);

    // Un fallo posterior no se propaga: la UI debe poder mostrar el período
    // como fallido sin que el job de la sincronización muera.
    $period->markAggregationFailed('fallo simulado de agregación');

    expect($period->refresh()->aggregation_status)->toBe(FiscalPeriod::AGGREGATION_FAILED);
});

it('vuelve a completed cuando el reintento funciona', function (): void {
    f507WithTax('2026-07-10', '4', '15', 1_000, 150, str_repeat('6', 49));

    $period = app(ComputePeriodAggregates::class)->handle($this->company, 2026, 7);

    expect($period->aggregation_attempts)->toBe(1);

    $period->markAggregationFailed('fallo previo');

    expect($period->refresh()->aggregation_status)->toBe(FiscalPeriod::AGGREGATION_FAILED);

    $retried = app(ComputePeriodAggregates::class)->handle($this->company, 2026, 7);

    expect($retried->aggregation_status)->toBe(FiscalPeriod::AGGREGATION_COMPLETED)
        ->and($retried->aggregation_error)->toBeNull()
        ->and($retried->aggregation_attempts)->toBe(3);
});
