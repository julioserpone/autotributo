<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Database\Seeders\EconomicActivityCodeSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'test']);
    app(TenantContext::class)->initialize($this->tenant);

    $user = User::factory()->create();

    $roleModel = Role::firstOrCreate([
        'name' => MembershipRole::Owner->value,
        'guard_name' => 'web',
        'tenant_id' => $this->tenant->id,
    ]);

    $user->assignRole($roleModel);

    $this->owner = $user;

    $this->seed(EconomicActivityCodeSeeder::class);
});

it('lista solo los códigos seleccionables por defecto', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/economic-activity-codes')
        ->assertOk()
        ->assertJsonStructure(['data' => [['code', 'description']]])
        ->assertJsonCount(3125, 'data');
});

it('lista el catálogo completo con all=1', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/economic-activity-codes?all=1')
        ->assertOk()
        ->assertJsonCount(6378, 'data');
});

it('filtra por código o descripción', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/economic-activity-codes?q=trigo')
        ->assertOk()
        ->assertJsonFragment(['code' => 'A01111101']);
});

it('devuelve 401 sin autenticación', function (): void {
    $this->getJson('/api/v1/economic-activity-codes')->assertUnauthorized();
});
