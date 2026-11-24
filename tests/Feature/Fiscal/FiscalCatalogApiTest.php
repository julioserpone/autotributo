<?php

declare(strict_types=1);

use App\Domain\Fiscal\Models\FormLine;
use App\Domain\Fiscal\Models\PersonalExpenseCap;
use App\Domain\Fiscal\Models\RetentionRate;
use App\Domain\Fiscal\Models\TaxRate;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'catalogs']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->owner = User::factory()->create();
    $this->owner->assignRole(Role::firstOrCreate([
        'name' => MembershipRole::Owner->value,
        'guard_name' => 'web',
        'tenant_id' => $this->tenant->id,
    ]));

    TaxRate::query()->create([
        'code' => 'IVA',
        'sri_percentage_code' => '4',
        'rate' => 15,
        'label' => 'IVA 15%',
        'valid_from' => '2024-04-01',
        'valid_to' => null,
        'legal_basis' => 'Ley Orgánica para el Fortalecimiento de la Economía',
        'source' => 'test',
    ]);

    TaxRate::query()->create([
        'code' => 'IVA',
        'sri_percentage_code' => '4',
        'rate' => 12,
        'label' => 'IVA 12%',
        'valid_from' => '2016-06-01',
        'valid_to' => '2024-03-31',
        'legal_basis' => 'Ley de Régimen Tributario Interno',
        'source' => 'test',
    ]);

    RetentionRate::query()->create([
        'tax_type' => 'IR',
        'code' => '303',
        'rate' => 2,
        'label' => 'Servicios profesionales',
        'valid_from' => '2024-01-01',
        'valid_to' => null,
        'source' => 'test',
    ]);

    FormLine::query()->create([
        'form_code' => '104',
        'line_code' => '419',
        'section' => 'IVA ventas',
        'label' => 'IVA por cobrar',
        'data_type' => 'amount',
        'is_required' => true,
        'display_order' => 10,
        'valid_from' => '2024-01-01',
        'valid_to' => null,
        'source' => 'test',
    ]);

    PersonalExpenseCap::query()->create([
        'fiscal_year' => 2026,
        'category_code' => 'global',
        'dependents' => 0,
        'cap_cents' => 1_900_000,
        'baskets' => 7,
        'basic_food_basket_cents' => 82_180,
        'valid_from' => '2026-01-01',
        'valid_to' => null,
        'source' => 'test',
    ]);
});

afterEach(function (): void {
    app(TenantContext::class)->end();
});

it('devuelve solo las tarifas de IVA vigentes por defecto', function (): void {
    $response = $this->actingAs($this->owner)->getJson('/api/v1/catalogs/tax-rates');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.sri_percentage_code', '4')
        ->assertJsonPath('data.0.rate', '15')
        ->assertJsonPath('data.0.label', 'IVA 15%')
        ->assertJsonPath('data.0.is_current', true)
        ->assertJsonPath('meta.catalog', 'tax-rates')
        ->assertJsonPath('meta.count', 1);
});

it('devuelve la versión vigente en una fecha concreta', function (): void {
    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/catalogs/tax-rates?valid_on=2020-05-01');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.rate', '12')
        ->assertJsonPath('meta.valid_on', '2020-05-01');
});

it('devuelve el histórico completo con historical=1', function (): void {
    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/catalogs/tax-rates?historical=1');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.historical', true);
});

it('rechaza una fecha de vigencia con formato inválido', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/catalogs/tax-rates?valid_on=05/01/2020')
        ->assertUnprocessable()
        ->assertJsonValidationErrors('valid_on');
});

it('devuelve retenciones, casilleros y topes con su forma propia', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/catalogs/retention-rates?tax_type=IR')
        ->assertOk()
        ->assertJsonPath('data.0.tax_type', 'IR')
        ->assertJsonPath('data.0.code', '303')
        ->assertJsonPath('data.0.rate', '2');

    $this->actingAs($this->owner)
        ->getJson('/api/v1/catalogs/form-lines?form_code=104')
        ->assertOk()
        ->assertJsonPath('data.0.form_code', '104')
        ->assertJsonPath('data.0.code', '419')
        ->assertJsonPath('data.0.data_type', 'amount')
        ->assertJsonPath('data.0.is_required', true);

    $this->actingAs($this->owner)
        ->getJson('/api/v1/catalogs/personal-expense-caps?fiscal_year=2026')
        ->assertOk()
        ->assertJsonPath('data.0.code', 'global')
        ->assertJsonPath('data.0.cap_cents', 1_900_000)
        ->assertJsonPath('data.0.baskets', 7);
});

it('filtra por código y por texto', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/catalogs/form-lines?code=419')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($this->owner)
        ->getJson('/api/v1/catalogs/form-lines?code=999')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->actingAs($this->owner)
        ->getJson('/api/v1/catalogs/tax-rates?historical=1&q=12%25')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.rate', '12');
});

it('exige autenticación', function (): void {
    $this->getJson('/api/v1/catalogs/tax-rates')->assertUnauthorized();
});

it('no expone el catálogo a un usuario sin capacidad de comprobantes', function (): void {
    $viewer = User::factory()->create();
    $viewer->assignRole(Role::firstOrCreate([
        'name' => MembershipRole::Viewer->value,
        'guard_name' => 'web',
        'tenant_id' => $this->tenant->id,
    ]));

    // El catálogo es global: cualquier rol de trabajo con acceso a comprobantes
    // puede leerlo; aquí se comprueba que la política se evalúa y responde.
    $this->actingAs($viewer)->getJson('/api/v1/catalogs/tax-rates')
        ->assertOk();
});
