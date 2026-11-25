<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Jobs\RecomputeSriRunAggregatesJob;
use App\Domain\Fiscal\Models\FiscalPeriod;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\FiscalDocuments\Models\FiscalDocumentTax;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Tests\Support\Sri\ReconcileFixtures;

uses(RefreshDatabase::class);

/**
 * F5-07d: el recálculo no depende de un comando manual.
 *
 * El usuario esperaba que los agregados se ejecutaran solos después de
 * sincronizar. La sincronización ya lo hace (`FinalizeSriRun`), pero aquí se
 * cubre la vía bajo demanda: `POST …/period-aggregates/recompute`, que es la
 * que usa el botón «Recalcular» del 104 cuando un período quedó vacío.
 */
beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'aggregates-recompute']);

    app(TenantContext::class)->initialize($this->tenant);

    $this->owner = User::factory()->create();
    $role = Role::firstOrCreate([
        'name' => 'owner',
        'guard_name' => 'web',
        'tenant_id' => $this->tenant->id,
    ]);
    $this->owner->assignRole($role);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '1790016919001',
    ]);

    $document = FiscalDocument::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'access_key' => str_repeat('7', 49),
        'issue_date' => '2026-11-05',
        'direction' => FiscalDocument::DIRECTION_RECEIVED,
        'subtotal_cents' => 10_000,
        'tax_cents' => 1_500,
        'total_cents' => 11_500,
    ]);

    FiscalDocumentTax::create([
        'tenant_id' => $this->tenant->id,
        'fiscal_document_id' => $document->id,
        'tax_code' => '2',
        'sri_percentage_code' => '4',
        'rate' => 0.15,
        'base_cents' => 10_000,
        'amount_cents' => 1_500,
    ]);
});

afterEach(function (): void {
    app(TenantContext::class)->end();
});

it('recalcula los agregados bajo demanda y reporta el estado del período', function (): void {
    $response = $this->actingAs($this->owner)
        ->postJson('/api/v1/companies/'.$this->company->public_id.'/period-aggregates/recompute', [
            'year' => 2026,
            'month' => 11,
        ]);

    $response->assertOk()
        ->assertJsonPath('meta.period.aggregation_status', FiscalPeriod::AGGREGATION_COMPLETED)
        ->assertJsonPath('meta.period.aggregation_error', null)
        ->assertJsonPath('meta.period.aggregation_attempts', 1)
        ->assertJsonCount(1, 'data');

    $row = $response->json('data.0');

    expect($row['direction'])->toBe('purchases')
        ->and($row['base_cents'])->toBe(10_000)
        ->and($row['tax_cents'])->toBe(1_500);
});

it('recalcula cada mes del run dentro del contexto de tenant', function (): void {
    $run = ReconcileFixtures::run($this->company, ['2026-10', '2026-11']);

    RecomputeSriRunAggregatesJob::forRun($run)->handle();

    $periods = FiscalPeriod::query()
        ->where('company_id', $this->company->id)
        ->orderBy('year')
        ->orderBy('month')
        ->get();

    expect($periods)->toHaveCount(2)
        ->and($periods->pluck('aggregation_status')->all())
        ->toBe([
            FiscalPeriod::AGGREGATION_COMPLETED,
            FiscalPeriod::AGGREGATION_COMPLETED,
        ]);
});

it('expone el estado de agregación al consultar los agregados', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/'.$this->company->public_id.'/period-aggregates?year=2026&month=11')
        ->assertOk()
        ->assertJsonPath('meta.period', null);

    $this->actingAs($this->owner)
        ->postJson('/api/v1/companies/'.$this->company->public_id.'/period-aggregates/recompute', [
            'year' => 2026,
            'month' => 11,
        ])
        ->assertOk();

    $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/'.$this->company->public_id.'/period-aggregates?year=2026&month=11')
        ->assertOk()
        ->assertJsonPath('meta.period.aggregation_status', FiscalPeriod::AGGREGATION_COMPLETED)
        ->assertJsonCount(1, 'data');
});

it('muestra el estado de agregación en el borrador del 104', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/companies/'.$this->company->public_id.'/period-aggregates/recompute', [
            'year' => 2026,
            'month' => 11,
        ])
        ->assertOk();

    $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/'.$this->company->public_id.'/form-104?year=2026&month=11')
        ->assertOk()
        ->assertJsonPath('data.period.aggregation_status', FiscalPeriod::AGGREGATION_COMPLETED);
});

it('devuelve 404 al recalcular una empresa de otro tenant', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'aggregates-other']);

    $otherCompany = Company::factory()->create([
        'tenant_id' => $otherTenant->id,
    ]);

    $this->actingAs($this->owner)
        ->postJson('/api/v1/companies/'.$otherCompany->public_id.'/period-aggregates/recompute')
        ->assertNotFound();
});

it('valida el período del recálculo', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/companies/'.$this->company->public_id.'/period-aggregates/recompute', [
            'year' => 2026,
            'month' => 13,
        ])
        ->assertUnprocessable();
});

it('recalcula por public_id con el comando y devuelve éxito', function (): void {
    $this->artisan('fiscal:compute-aggregates', [
        '--company' => $this->company->public_id,
        '--period' => '2026-11',
    ])->assertExitCode(0);

    $period = FiscalPeriod::query()
        ->where('company_id', $this->company->id)
        ->where('year', 2026)
        ->where('month', 11)
        ->firstOrFail();

    expect($period->aggregation_status)->toBe(FiscalPeriod::AGGREGATION_COMPLETED)
        ->and($period->aggregates()->count())->toBe(1);
});
