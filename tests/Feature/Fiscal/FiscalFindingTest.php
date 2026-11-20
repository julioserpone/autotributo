<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Enums\FiscalRiskCode;
use App\Domain\Fiscal\Enums\FiscalRiskSeverity;
use App\Domain\Fiscal\Enums\FiscalRiskStatus;
use App\Domain\Fiscal\Models\FiscalFinding;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\QueryException;
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

it('crea un hallazgo fiscal con los enums casteados', function (): void {
    $finding = FiscalFinding::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'code' => FiscalRiskCode::TotalsMismatch->value,
        'severity' => FiscalRiskSeverity::Critical->value,
        'status' => FiscalRiskStatus::Open->value,
    ]);

    expect($finding->code)->toBe(FiscalRiskCode::TotalsMismatch)
        ->and($finding->severity)->toBe(FiscalRiskSeverity::Critical)
        ->and($finding->status)->toBe(FiscalRiskStatus::Open)
        ->and($finding->tenant_id)->toBe($this->tenant->id);
});

it('aisla los hallazgos por tenant', function (): void {
    FiscalFinding::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
    ]);

    $otherTenant = Tenant::factory()->create(['slug' => 'other']);
    app(TenantContext::class)->initialize($otherTenant);

    expect(FiscalFinding::query()->count())->toBe(0);
});

it('no duplica hallazgos abiertos con la misma procedencia', function (): void {
    FiscalFinding::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'code' => FiscalRiskCode::TotalsMismatch->value,
        'source_type' => 'fiscal_document',
        'source_id' => 42,
        'status' => FiscalRiskStatus::Open->value,
    ]);

    expect(fn () => FiscalFinding::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'code' => FiscalRiskCode::TotalsMismatch->value,
        'source_type' => 'fiscal_document',
        'source_id' => 42,
        'status' => FiscalRiskStatus::Open->value,
    ]))->toThrow(QueryException::class);
});

it('permite reabrir un hallazgo tras resolverlo', function (): void {
    FiscalFinding::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'code' => FiscalRiskCode::TotalsMismatch->value,
        'source_type' => 'fiscal_document',
        'source_id' => 7,
        'status' => FiscalRiskStatus::Resolved->value,
    ]);

    $reopened = FiscalFinding::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'code' => FiscalRiskCode::TotalsMismatch->value,
        'source_type' => 'fiscal_document',
        'source_id' => 7,
        'status' => FiscalRiskStatus::Open->value,
    ]);

    expect($reopened->id)->toBeGreaterThan(0);
});
