<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Models\FiscalFinding;
use App\Domain\Fiscal\Models\FiscalPeriod;
use App\Domain\Fiscal\Models\PeriodAggregate;
use App\Domain\Fiscal\Models\PersonalExpenseCap;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'dashboard-test']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->owner = User::factory()->create();
    $role = Role::firstOrCreate([
        'name' => MembershipRole::Owner->value,
        'guard_name' => 'web',
        'tenant_id' => $this->tenant->id,
    ]);
    $this->owner->assignRole($role);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '1790016919001',
    ]);

    $this->period = FiscalPeriod::query()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'year' => 2026,
        'month' => 9,
        'status' => 'open',
        'opened_at' => now(),
    ]);

    PeriodAggregate::query()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'fiscal_period_id' => $this->period->id,
        'direction' => 'sales',
        'document_type' => SriDocumentType::FiscalDocument->value,
        'tax_code' => '2',
        'sri_percentage_code' => '4',
        'rate' => 0.15,
        'base_cents' => 10_000,
        'tax_cents' => 1_500,
        'retention_cents' => 0,
        'documents_count' => 1,
        'computed_at' => now(),
    ]);

    PeriodAggregate::query()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'fiscal_period_id' => $this->period->id,
        'direction' => 'purchases',
        'document_type' => SriDocumentType::FiscalDocument->value,
        'tax_code' => '2',
        'sri_percentage_code' => '4',
        'rate' => 0.15,
        'base_cents' => 5_000,
        'tax_cents' => 750,
        'retention_cents' => 0,
        'documents_count' => 2,
        'computed_at' => now(),
    ]);

    PeriodAggregate::query()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'fiscal_period_id' => $this->period->id,
        'direction' => 'sales',
        'document_type' => SriDocumentType::Withholding->value,
        'tax_code' => '721',
        'sri_percentage_code' => '',
        'rate' => 0.3,
        'base_cents' => 1_000,
        'tax_cents' => 0,
        'retention_cents' => 300,
        'documents_count' => 1,
        'computed_at' => now(),
    ]);

    PeriodAggregate::query()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'fiscal_period_id' => $this->period->id,
        'direction' => 'purchases',
        'document_type' => SriDocumentType::Withholding->value,
        'tax_code' => '303',
        'sri_percentage_code' => '',
        'rate' => 0.02,
        'base_cents' => 10_000,
        'tax_cents' => 0,
        'retention_cents' => 200,
        'documents_count' => 1,
        'computed_at' => now(),
    ]);

    FiscalDocument::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'direction' => FiscalDocument::DIRECTION_ISSUED,
        'issuer_ruc' => $this->company->ruc,
        'issue_date' => '2026-09-10',
        'total_cents' => 11_500,
    ]);

    FiscalDocument::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'direction' => FiscalDocument::DIRECTION_RECEIVED,
        'issuer_ruc' => '1790012345001',
        'issuer_name' => 'PROVEEDOR UNO',
        'issue_date' => '2026-09-11',
        'total_cents' => 5_750,
    ]);

    FiscalDocument::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'direction' => FiscalDocument::DIRECTION_RECEIVED,
        'issuer_ruc' => '1790012345001',
        'issuer_name' => 'PROVEEDOR UNO',
        'issue_date' => '2026-09-12',
        'total_cents' => 2_300,
    ]);

    FiscalFinding::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'severity' => 'warning',
        'status' => 'open',
        'source_id' => 1,
    ]);

    FiscalFinding::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'severity' => 'critical',
        'status' => 'open',
        'source_id' => 2,
    ]);

    FiscalFinding::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'severity' => 'critical',
        'status' => 'resolved',
        'source_id' => 3,
    ]);

    PersonalExpenseCap::query()->create([
        'fiscal_year' => 2026,
        'category_code' => 'global',
        'dependents' => 0,
        'cap_cents' => 1_900_000,
        'baskets' => 7,
        'basic_food_basket_cents' => 82_180,
        'valid_from' => '2026-01-01',
        'source' => 'test',
    ]);
});

afterEach(function (): void {
    app(TenantContext::class)->end();
});

it('devuelve el panel del período con IVA, retenciones, proveedores y hallazgos', function (): void {
    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/'.$this->company->public_id.'/dashboard?year=2026&month=9');

    $response->assertOk()
        ->assertJsonPath('data.iva.sales.base_cents', 10_000)
        ->assertJsonPath('data.iva.sales.tax_cents', 1_500)
        ->assertJsonPath('data.iva.purchases.base_cents', 5_000)
        ->assertJsonPath('data.iva.purchases.tax_cents', 750)
        ->assertJsonPath('data.iva.net_cents', 750)
        ->assertJsonPath('data.withholdings.received_cents', 300)
        ->assertJsonPath('data.withholdings.practiced_cents', 200)
        ->assertJsonPath('data.top_providers.0.issuer_ruc', '1790012345001')
        ->assertJsonPath('data.top_providers.0.total_cents', 8_050)
        ->assertJsonPath('data.top_providers.0.documents_count', 2)
        ->assertJsonPath('data.findings.open_total', 2)
        ->assertJsonPath('data.findings.by_severity.warning', 1)
        ->assertJsonPath('data.findings.by_severity.critical', 1)
        ->assertJsonCount(1, 'data.personal_expense_caps');
});

it('acepta el company_id por query y devuelve ceros si el período no existe', function (): void {
    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/dashboard?company_id='.$this->company->public_id.'&year=2026&month=4');

    $response->assertOk()
        ->assertJsonPath('data.company.public_id', $this->company->public_id)
        ->assertJsonPath('data.iva.sales.base_cents', 0)
        ->assertJsonPath('data.iva.net_cents', 0)
        ->assertJsonPath('data.withholdings.received_cents', 0);
});

it('devuelve 404 para una empresa de otro tenant', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'other-dashboard']);

    app(TenantContext::class)->run($otherTenant, function () use ($otherTenant, &$otherCompany): void {
        $otherCompany = Company::factory()->create([
            'tenant_id' => $otherTenant->id,
            'ruc' => '0990000018002',
        ]);
    });

    $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/'.$otherCompany->public_id.'/dashboard')
        ->assertNotFound();
});
