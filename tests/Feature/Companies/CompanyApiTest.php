<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function companyPayload(array $overrides = []): array
{
    return array_merge([
        'ruc' => '0602910945001',
        'legal_name' => 'Estudio Contable Demo S.A.',
        'trade_name' => 'Demo',
        'regime' => 'general',
        'is_accounting_required' => true,
        'is_withholding_agent' => false,
        'economic_activity_code' => '692001',
        'address' => 'Av. Amazonas N34-451',
        'email' => 'contacto@demo.test',
        'phone' => '+593 2 123 4567',
        'fiscal_year_start_month' => 1,
    ], $overrides);
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'test']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->makeUser = function (MembershipRole $role): User {
        app(TenantContext::class)->initialize($this->tenant);

        $user = User::factory()->create();

        $roleModel = Role::firstOrCreate([
            'name' => $role->value,
            'guard_name' => 'web',
            'tenant_id' => $this->tenant->id,
        ]);

        $user->assignRole($roleModel);

        return $user;
    };

    $this->owner = ($this->makeUser)(MembershipRole::Owner);
    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '0990000018001',
    ]);
});

it('crea una empresa con RUC válido en el tenant activo', function (): void {
    $response = $this->actingAs($this->owner)
        ->postJson('/api/v1/companies', companyPayload());

    $response->assertCreated()
        ->assertJsonPath('data.ruc', '0602910945001')
        ->assertJsonPath('data.legal_name', 'Estudio Contable Demo S.A.')
        ->assertJsonPath('data.regime', 'general')
        ->assertJsonPath('data.status', Company::STATUS_ACTIVE)
        ->assertJsonStructure([
            'data' => [
                'id',
                'ruc',
                'legal_name',
                'trade_name',
                'regime',
                'regime_label',
                'is_accounting_required',
                'is_withholding_agent',
                'withholding_resolution',
                'economic_activity_code',
                'address',
                'email',
                'phone',
                'fiscal_year_start_month',
                'status',
                'settings',
                'created_at',
                'updated_at',
            ],
        ]);

    expect($response->json('data.id'))->toBeString()->toHaveLength(26);

    $this->assertDatabaseHas('companies', [
        'tenant_id' => $this->tenant->id,
        'ruc' => '0602910945001',
        'legal_name' => 'Estudio Contable Demo S.A.',
        'created_by' => $this->owner->id,
        'status' => Company::STATUS_ACTIVE,
    ]);
});

it('ignora un tenant_id inyectado en el payload', function (): void {
    $otherTenant = Tenant::factory()->create();

    $this->actingAs($this->owner)
        ->postJson('/api/v1/companies', companyPayload([
            'tenant_id' => $otherTenant->id,
        ]))
        ->assertCreated();

    $company = Company::query()->where('ruc', '0602910945001')->firstOrFail();

    expect($company->tenant_id)->toBe($this->tenant->id);
});

it('rechaza un RUC inválido', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/companies', companyPayload([
            'ruc' => '0926687858001',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ruc');

    $this->assertDatabaseMissing('companies', ['ruc' => '0926687858001']);
});

it('rechaza un RUC duplicado dentro del mismo tenant', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/companies', companyPayload())
        ->assertCreated();

    $this->actingAs($this->owner)
        ->postJson('/api/v1/companies', companyPayload([
            'legal_name' => 'Otra razón social',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('ruc');

    expect(DB::table('companies')->where('ruc', '0602910945001')->count())->toBe(1);
});

it('permite el mismo RUC en otro tenant sin filtrar datos', function (): void {
    $otherTenant = Tenant::factory()->create();

    Company::factory()->create([
        'tenant_id' => $otherTenant->id,
        'ruc' => '0602910945001',
        'legal_name' => 'Empresa de otro tenant',
    ]);

    $this->actingAs($this->owner)
        ->postJson('/api/v1/companies', companyPayload())
        ->assertCreated();

    expect(DB::table('companies')->where('ruc', '0602910945001')->count())->toBe(2);

    $this->actingAs($this->owner)
        ->getJson('/api/v1/companies')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonMissing(['legal_name' => 'Empresa de otro tenant']);
});

it('lista y filtra empresas del tenant', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/companies?search=0990000018001')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.ruc', '0990000018001');

    $this->actingAs($this->owner)
        ->getJson('/api/v1/companies?status=archived')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('muestra una empresa por public_id', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/'.$this->company->public_id)
        ->assertOk()
        ->assertJsonPath('data.id', $this->company->public_id)
        ->assertJsonPath('data.ruc', '0990000018001');
});

it('actualiza el perfil tributario', function (): void {
    $this->actingAs($this->owner)
        ->patchJson('/api/v1/companies/'.$this->company->public_id, [
            'legal_name' => 'Comercial Andina Cía. Ltda.',
            'trade_name' => 'Andina',
            'regime' => 'rimpe_emprendedor',
            'is_accounting_required' => false,
            'is_withholding_agent' => true,
            'withholding_resolution' => 'NAC-DGERCGC23-00000015',
            'address' => 'Av. 6 de Diciembre N24-100',
        ])
        ->assertOk()
        ->assertJsonPath('data.legal_name', 'Comercial Andina Cía. Ltda.')
        ->assertJsonPath('data.regime', 'rimpe_emprendedor')
        ->assertJsonPath('data.is_withholding_agent', true);

    $this->assertDatabaseHas('companies', [
        'id' => $this->company->id,
        'legal_name' => 'Comercial Andina Cía. Ltda.',
        'trade_name' => 'Andina',
        'regime' => 'rimpe_emprendedor',
        'is_accounting_required' => false,
        'is_withholding_agent' => true,
        'withholding_resolution' => 'NAC-DGERCGC23-00000015',
    ]);
});

it('exige resolución cuando la empresa es agente de retención', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/companies', companyPayload([
            'is_withholding_agent' => true,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('withholding_resolution');
});

it('rechaza régimen y mes fiscal inválidos', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/companies', companyPayload([
            'regime' => 'regimen_inventado',
            'fiscal_year_start_month' => 13,
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['regime', 'fiscal_year_start_month']);
});

it('archiva la empresa y la conserva con soft delete', function (): void {
    $this->actingAs($this->owner)
        ->deleteJson('/api/v1/companies/'.$this->company->public_id)
        ->assertNoContent();

    $this->assertDatabaseHas('companies', [
        'id' => $this->company->id,
        'status' => Company::STATUS_ARCHIVED,
    ]);

    expect(DB::table('companies')->where('id', $this->company->id)->value('deleted_at'))
        ->not->toBeNull();

    $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/'.$this->company->public_id)
        ->assertNotFound();
});

it('no expone empresas de otro tenant', function (): void {
    $otherTenant = Tenant::factory()->create();

    $foreign = Company::factory()->create([
        'tenant_id' => $otherTenant->id,
        'ruc' => '1760001550001',
    ]);

    $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/'.$foreign->public_id)
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->patchJson('/api/v1/companies/'.$foreign->public_id, [
            'legal_name' => 'Intento cross-tenant',
        ])
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->deleteJson('/api/v1/companies/'.$foreign->public_id)
        ->assertNotFound();
});

it('rechaza requests sin autenticación', function (): void {
    $this->getJson('/api/v1/companies')->assertUnauthorized();
});

it('permite lectura a assistant y viewer pero no escritura', function (MembershipRole $role): void {
    $user = ($this->makeUser)($role);

    $this->actingAs($user)
        ->getJson('/api/v1/companies')
        ->assertOk();

    $this->actingAs($user)
        ->getJson('/api/v1/companies/'.$this->company->public_id)
        ->assertOk();

    $this->actingAs($user)
        ->postJson('/api/v1/companies', companyPayload([
            'ruc' => '1760001550001',
        ]))
        ->assertForbidden();

    $this->actingAs($user)
        ->patchJson('/api/v1/companies/'.$this->company->public_id, [
            'legal_name' => 'No permitido',
        ])
        ->assertForbidden();

    $this->actingAs($user)
        ->deleteJson('/api/v1/companies/'.$this->company->public_id)
        ->assertForbidden();
})->with([
    'assistant' => MembershipRole::Assistant,
    'viewer' => MembershipRole::Viewer,
]);

it('permite a accountant gestionar empresas', function (): void {
    $accountant = ($this->makeUser)(MembershipRole::Accountant);

    $this->actingAs($accountant)
        ->postJson('/api/v1/companies', companyPayload([
            'ruc' => '1760001550001',
        ]))
        ->assertCreated();

    $this->actingAs($accountant)
        ->patchJson('/api/v1/companies/'.$this->company->public_id, [
            'legal_name' => 'Actualizada por contador',
        ])
        ->assertOk();

    $this->actingAs($accountant)
        ->deleteJson('/api/v1/companies/'.$this->company->public_id)
        ->assertNoContent();
});
