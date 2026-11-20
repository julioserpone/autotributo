<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Actions\DetectFiscalRisks;
use App\Domain\Fiscal\Enums\FiscalRiskCode;
use App\Domain\Fiscal\Enums\FiscalRiskStatus;
use App\Domain\Fiscal\Models\FiscalFinding;
use App\Domain\Fiscal\Models\GhostCompany;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'test']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '0990000018001',
    ]);
});

function makeDocument(array $overrides = []): FiscalDocument
{
    return FiscalDocument::factory()->create(array_merge([
        'tenant_id' => test()->tenant->id,
        'company_id' => test()->company->id,
    ], $overrides));
}

it('detecta totales inconsistentes y es idempotente', function (): void {
    makeDocument(['totals_mismatch' => true, 'issuer_ruc' => '1758871873001']);

    $first = app(DetectFiscalRisks::class)->handle($this->company);
    expect($first->created)->toBe(1)
        ->and(FiscalFinding::query()->where('code', FiscalRiskCode::TotalsMismatch->value)->count())->toBe(1);

    $second = app(DetectFiscalRisks::class)->handle($this->company);
    expect($second->created)->toBe(0)
        ->and(FiscalFinding::query()->where('code', FiscalRiskCode::TotalsMismatch->value)->count())->toBe(1);
});

it('detecta RUC inválido del emisor', function (): void {
    makeDocument(['issuer_ruc' => '123', 'totals_mismatch' => false]);

    $result = app(DetectFiscalRisks::class)->handle($this->company);

    expect($result->created)->toBe(1)
        ->and(FiscalFinding::query()->where('code', FiscalRiskCode::InvalidRuc->value)->count())->toBe(1);
});

it('detecta empresa fantasma del SRI', function (): void {
    GhostCompany::query()->create([
        'ruc' => '1758871873001',
        'legal_name' => 'FANTASMA S.A.',
        'published_at' => now()->toDateString(),
        'source_url' => 'https://example.test',
        'status' => 'active',
        'imported_at' => now(),
    ]);

    makeDocument(['issuer_ruc' => '1758871873001', 'totals_mismatch' => false]);

    $result = app(DetectFiscalRisks::class)->handle($this->company);

    expect($result->created)->toBe(1)
        ->and(FiscalFinding::query()->where('code', FiscalRiskCode::GhostCompany->value)->count())->toBe(1);
});

it('detecta comprobantes duplicados por numeración', function (): void {
    makeDocument([
        'issuer_ruc' => '1758871873001',
        'establishment' => '001',
        'emission_point' => '001',
        'sequential' => '000000123',
        'totals_mismatch' => false,
    ]);
    makeDocument([
        'issuer_ruc' => '1758871873001',
        'establishment' => '001',
        'emission_point' => '001',
        'sequential' => '000000123',
        'totals_mismatch' => false,
    ]);

    $result = app(DetectFiscalRisks::class)->handle($this->company);

    expect($result->created)->toBe(2)
        ->and(FiscalFinding::query()->where('code', FiscalRiskCode::DuplicateSequential->value)->count())->toBe(2);
});

it('auto-resuelve totales inconsistentes cuando el riesgo desaparece', function (): void {
    $document = makeDocument(['totals_mismatch' => true, 'issuer_ruc' => '1758871873001']);

    app(DetectFiscalRisks::class)->handle($this->company);
    expect(FiscalFinding::query()->where('code', FiscalRiskCode::TotalsMismatch->value)->where('status', FiscalRiskStatus::Open->value)->count())->toBe(1);

    $document->totals_mismatch = false;
    $document->save();

    $result = app(DetectFiscalRisks::class)->handle($this->company);

    expect($result->resolved)->toBe(1)
        ->and(FiscalFinding::query()->where('code', FiscalRiskCode::TotalsMismatch->value)->where('status', FiscalRiskStatus::Open->value)->count())->toBe(0);
});

it('no auto-resuelve riesgos que requieren acción humana', function (): void {
    makeDocument(['issuer_ruc' => '123', 'totals_mismatch' => false]);

    app(DetectFiscalRisks::class)->handle($this->company);
    expect(FiscalFinding::query()->where('code', FiscalRiskCode::InvalidRuc->value)->where('status', FiscalRiskStatus::Open->value)->count())->toBe(1);

    // El RUC sigue inválido; el hallazgo permanece abierto (no se auto-resuelve).
    $result = app(DetectFiscalRisks::class)->handle($this->company);

    expect(FiscalFinding::query()->where('code', FiscalRiskCode::InvalidRuc->value)->where('status', FiscalRiskStatus::Open->value)->count())->toBe(1);
});
