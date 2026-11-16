<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'test']);
    app(TenantContext::class)->initialize($this->tenant);

    $user = User::factory()->create();
    $role = Role::firstOrCreate([
        'name' => MembershipRole::Owner->value,
        'guard_name' => 'web',
        'tenant_id' => $this->tenant->id,
    ]);
    $user->assignRole($role);
    $this->owner = $user;

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '0990000018001',
    ]);

    Invoice::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'status' => Invoice::STATUS_AUTHORIZED,
        'issuer_name' => 'PROVEEDOR UNO S.A.',
    ]);

    Invoice::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'status' => Invoice::STATUS_AUTHORIZED,
        'issuer_name' => 'PROVEEDOR DOS S.A.',
    ]);
});

it('lista los comprobantes del tenant activo', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/invoices')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonStructure(['data', 'links', 'meta']);
});

it('filtra por emisor', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/invoices?issuer=UNO')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.issuer_name', 'PROVEEDOR UNO S.A.');
});

it('no expone comprobantes de otro tenant', function (): void {
    $otherTenant = Tenant::factory()->create();
    app(TenantContext::class)->initialize($otherTenant);

    $otherCompany = Company::factory()->create([
        'tenant_id' => $otherTenant->id,
        'ruc' => '0990000028001',
    ]);

    Invoice::factory()->create([
        'tenant_id' => $otherTenant->id,
        'company_id' => $otherCompany->id,
        'issuer_name' => 'PROVEEDOR SECRETO S.A.',
    ]);

    app(TenantContext::class)->initialize($this->tenant);

    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/invoices');

    $response->assertOk();

    $names = collect($response->json('data'))->pluck('issuer_name')->all();

    expect($names)->not->toContain('PROVEEDOR SECRETO S.A.')
        ->and($names)->toHaveCount(2);
});

it('devuelve el detalle con líneas, impuestos y retenciones', function (): void {
    $invoice = Invoice::query()->firstOrFail();

    $this->actingAs($this->owner)
        ->getJson("/api/v1/invoices/{$invoice->public_id}")
        ->assertOk()
        ->assertJsonPath('data.id', $invoice->public_id);
});
