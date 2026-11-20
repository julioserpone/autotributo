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

it('no genera hallazgos cuando el comprobante es sano', function (): void {
    makeDocument([
        'totals_mismatch' => false,
        'issuer_ruc' => '1758871873001',
    ]);

    $result = app(DetectFiscalRisks::class)->handle($this->company);

    expect($result->created)->toBe(0)
        ->and(FiscalFinding::query()->count())->toBe(0);
});

it('no genera hallazgo de empresa fantasma si el emisor no está en la lista', function (): void {
    makeDocument(['issuer_ruc' => '1758871873001', 'totals_mismatch' => false]);

    $result = app(DetectFiscalRisks::class)->handle($this->company);

    expect($result->created)->toBe(0)
        ->and(FiscalFinding::query()->where('code', FiscalRiskCode::GhostCompany->value)->count())->toBe(0);
});

it('no genera hallazgo de duplicado si la numeración es única', function (): void {
    makeDocument([
        'issuer_ruc' => '1758871873001',
        'establishment' => '001',
        'emission_point' => '001',
        'sequential' => '000000999',
        'totals_mismatch' => false,
    ]);

    $result = app(DetectFiscalRisks::class)->handle($this->company);

    expect(FiscalFinding::query()->where('code', FiscalRiskCode::DuplicateSequential->value)->count())->toBe(0);
});

it('auto-resuelve duplicados cuando ya no hay numeración repetida', function (): void {
    $first = makeDocument([
        'issuer_ruc' => '1758871873001',
        'establishment' => '001',
        'emission_point' => '001',
        'sequential' => '000000777',
        'totals_mismatch' => false,
    ]);
    $second = makeDocument([
        'issuer_ruc' => '1758871873001',
        'establishment' => '001',
        'emission_point' => '001',
        'sequential' => '000000777',
        'totals_mismatch' => false,
    ]);

    app(DetectFiscalRisks::class)->handle($this->company);
    expect(FiscalFinding::query()->where('code', FiscalRiskCode::DuplicateSequential->value)->where('status', FiscalRiskStatus::Open->value)->count())->toBe(2);

    $second->sequential = '000000888';
    $second->save();

    $result = app(DetectFiscalRisks::class)->handle($this->company);

    expect($result->resolved)->toBe(2)
        ->and(FiscalFinding::query()->where('code', FiscalRiskCode::DuplicateSequential->value)->where('status', FiscalRiskStatus::Open->value)->count())->toBe(0);
});

it('no auto-resuelve empresas fantasma (requieren acción humana)', function (): void {
    GhostCompany::query()->create([
        'ruc' => '1758871873001',
        'legal_name' => 'FANTASMA S.A.',
        'published_at' => now()->toDateString(),
        'source_url' => 'https://example.test',
        'status' => 'active',
        'imported_at' => now(),
    ]);

    $document = makeDocument(['issuer_ruc' => '1758871873001', 'totals_mismatch' => false]);

    app(DetectFiscalRisks::class)->handle($this->company);
    expect(FiscalFinding::query()->where('code', FiscalRiskCode::GhostCompany->value)->where('status', FiscalRiskStatus::Open->value)->count())->toBe(1);

    GhostCompany::query()->where('ruc', '1758871873001')->delete();

    app(DetectFiscalRisks::class)->handle($this->company);

    expect(FiscalFinding::query()->where('code', FiscalRiskCode::GhostCompany->value)->where('status', FiscalRiskStatus::Open->value)->count())->toBe(1);
});

it('actualiza los datos de un hallazgo existente cuando cambia el título', function (): void {
    $document = makeDocument(['totals_mismatch' => true, 'issuer_ruc' => '1758871873001']);

    app(DetectFiscalRisks::class)->handle($this->company);

    $finding = FiscalFinding::query()->firstOrFail();
    $finding->title = 'Título desactualizado';
    $finding->save();

    $result = app(DetectFiscalRisks::class)->handle($this->company);

    expect($result->updated)->toBe(1)
        ->and($finding->fresh()->title)->toBe('Totales inconsistentes');
});

it('actualiza un hallazgo existente sin duplicarlo cuando se re-detecta', function (): void {
    $document = makeDocument(['totals_mismatch' => true, 'issuer_ruc' => '1758871873001']);

    app(DetectFiscalRisks::class)->handle($this->company);

    $document->totals_mismatch = true;
    $document->total_cents = 999_999;
    $document->save();

    $result = app(DetectFiscalRisks::class)->handle($this->company);

    expect($result->created)->toBe(0)
        ->and(FiscalFinding::query()->where('code', FiscalRiskCode::TotalsMismatch->value)->count())->toBe(1);
});

it('aisla la detección entre tenants', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'other-risk']);

    app(TenantContext::class)->run($otherTenant, function () use ($otherTenant): void {
        $otherCompany = Company::factory()->create([
            'tenant_id' => $otherTenant->id,
            'ruc' => '0990000018002',
        ]);

        FiscalDocument::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'totals_mismatch' => true,
            'issuer_ruc' => '1758871873001',
        ]);

        app(DetectFiscalRisks::class)->handle($otherCompany);
    });

    expect(FiscalFinding::query()->count())->toBe(0);
});
