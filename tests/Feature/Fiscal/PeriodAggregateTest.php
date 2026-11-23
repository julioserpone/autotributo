<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Actions\ComputePeriodAggregates;
use App\Domain\Fiscal\Models\PeriodAggregate;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\FiscalDocuments\Models\FiscalDocumentTax;
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
