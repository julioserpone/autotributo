<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Actions\ComputePeriodAggregates;
use App\Domain\Fiscal\Models\PeriodAggregate;
use App\Domain\Fiscal\Models\TaxRate;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\FiscalDocuments\Models\FiscalDocumentTax;
use App\Domain\FiscalDocuments\Models\RetentionLine;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    app(TenantContext::class)->initialize($this->tenant);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '1790016919001',
    ]);
});

afterEach(function (): void {
    app(TenantContext::class)->end();
});

it('crea agregados idempotentemente', function (): void {
    $document = FiscalDocument::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'issue_date' => '2026-03-15',
        'subtotal_cents' => 10000,
        'tax_cents' => 1500,
        'total_cents' => 11500,
    ]);

    FiscalDocumentTax::create([
        'tenant_id' => $this->tenant->id,
        'fiscal_document_id' => $document->id,
        'tax_code' => '2',
        'sri_percentage_code' => '4',
        'rate' => 0.15,
        'base_cents' => 10000,
        'amount_cents' => 1500,
    ]);

    $compute = app(ComputePeriodAggregates::class);

    $period1 = $compute->handle($this->company, 2026, 3);
    expect(PeriodAggregate::query()->where('fiscal_period_id', $period1->id)->count())->toBe(1);

    // Idempotencia: segunda ejecución no duplica.
    $period2 = $compute->handle($this->company, 2026, 3);
    expect($period2->id)->toBe($period1->id);
    expect(PeriodAggregate::query()->where('fiscal_period_id', $period1->id)->count())->toBe(1);

    $aggregate = PeriodAggregate::query()->where('fiscal_period_id', $period1->id)->first();
    expect($aggregate->base_cents)->toBe(10000);
    expect($aggregate->tax_cents)->toBe(1500);
    expect($aggregate->documents_count)->toBe(1);
});

it('no crea agregados si no hay comprobantes', function (): void {
    $period = app(ComputePeriodAggregates::class)->handle($this->company, 2026, 4);

    expect(PeriodAggregate::query()->where('fiscal_period_id', $period->id)->count())->toBe(0);
});

it('agrega ventas cuando el emisor es la empresa', function (): void {
    $document = FiscalDocument::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'direction' => FiscalDocument::DIRECTION_ISSUED,
        'issuer_ruc' => $this->company->ruc,
        'issue_date' => '2026-05-10',
    ]);

    FiscalDocumentTax::create([
        'tenant_id' => $this->tenant->id,
        'fiscal_document_id' => $document->id,
        'tax_code' => '2',
        'sri_percentage_code' => '4',
        'rate' => 0.15,
        'base_cents' => 20000,
        'amount_cents' => 3000,
    ]);

    $period = app(ComputePeriodAggregates::class)->handle($this->company, 2026, 5);

    $sale = PeriodAggregate::query()
        ->where('fiscal_period_id', $period->id)
        ->where('direction', 'sales')
        ->first();

    expect($sale)->not->toBeNull()
        ->and($sale->base_cents)->toBe(20000)
        ->and($sale->tax_cents)->toBe(3000)
        ->and($sale->documents_count)->toBe(1);
});

it('agrega retenciones practicadas en compras y recibidas en ventas', function (): void {
    $practiced = FiscalDocument::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'document_type' => SriDocumentType::Withholding,
        'direction' => FiscalDocument::DIRECTION_ISSUED,
        'issuer_ruc' => $this->company->ruc,
        'issue_date' => '2026-06-05',
    ]);

    RetentionLine::create([
        'tenant_id' => $this->tenant->id,
        'fiscal_document_id' => $practiced->id,
        'tax_type' => 'IR',
        'retention_code' => '303',
        'base_cents' => 10000,
        'rate' => 0.02,
        'amount_cents' => 200,
        'document_number' => '001-001-000000123',
    ]);

    $received = FiscalDocument::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'document_type' => SriDocumentType::Withholding,
        'direction' => FiscalDocument::DIRECTION_RECEIVED,
        'issuer_ruc' => '1790012345001',
        'issue_date' => '2026-06-06',
    ]);

    RetentionLine::create([
        'tenant_id' => $this->tenant->id,
        'fiscal_document_id' => $received->id,
        'tax_type' => 'IVA',
        'retention_code' => '721',
        'base_cents' => 5000,
        'rate' => 0.3,
        'amount_cents' => 1500,
        'document_number' => '001-001-000000456',
    ]);

    $period = app(ComputePeriodAggregates::class)->handle($this->company, 2026, 6);

    $practicedBucket = PeriodAggregate::query()
        ->where('fiscal_period_id', $period->id)
        ->where('direction', 'purchases')
        ->where('document_type', '07')
        ->where('tax_code', '303')
        ->first();

    $receivedBucket = PeriodAggregate::query()
        ->where('fiscal_period_id', $period->id)
        ->where('direction', 'sales')
        ->where('document_type', '07')
        ->where('tax_code', '721')
        ->first();

    expect($practicedBucket)->not->toBeNull()
        ->and($practicedBucket->retention_cents)->toBe(200)
        ->and($practicedBucket->base_cents)->toBe(10000)
        ->and($receivedBucket)->not->toBeNull()
        ->and($receivedBucket->retention_cents)->toBe(1500)
        ->and($receivedBucket->base_cents)->toBe(5000);
});

it('usa la tarifa del catálogo cuando el impuesto de cabecera no la publica', function (): void {
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

    $document = FiscalDocument::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'issue_date' => '2026-06-15',
        'subtotal_cents' => 2_620,
        'tax_cents' => 0,
        'total_cents' => 2_620,
    ]);

    // La factura del SRI no trae `<tarifa>` en `totalImpuesto`: llega en 0.
    FiscalDocumentTax::create([
        'tenant_id' => $this->tenant->id,
        'fiscal_document_id' => $document->id,
        'tax_code' => '2',
        'sri_percentage_code' => '0',
        'rate' => 0,
        'base_cents' => 2_620,
        'amount_cents' => 0,
    ]);

    $period = app(ComputePeriodAggregates::class)->handle($this->company, 2026, 6);

    $aggregate = PeriodAggregate::query()->where('fiscal_period_id', $period->id)->firstOrFail();

    expect((float) $aggregate->rate)->toBe(0.0)
        ->and($aggregate->base_cents)->toBe(2_620);

    $vatDocument = FiscalDocument::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'issue_date' => '2026-06-20',
        'subtotal_cents' => 10_000,
        'tax_cents' => 1_500,
        'total_cents' => 11_500,
    ]);

    FiscalDocumentTax::create([
        'tenant_id' => $this->tenant->id,
        'fiscal_document_id' => $vatDocument->id,
        'tax_code' => '2',
        'sri_percentage_code' => '4',
        'rate' => 0,
        'base_cents' => 10_000,
        'amount_cents' => 1_500,
    ]);

    $period = app(ComputePeriodAggregates::class)->handle($this->company, 2026, 6);

    $vatAggregate = PeriodAggregate::query()
        ->where('fiscal_period_id', $period->id)
        ->where('sri_percentage_code', '4')
        ->firstOrFail();

    expect((float) $vatAggregate->rate)->toBe(15.0);
});
