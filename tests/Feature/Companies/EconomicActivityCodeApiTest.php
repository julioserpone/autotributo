<?php

declare(strict_types=1);

use App\Domain\Companies\Models\EconomicActivityCode;
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

    $roleModel = Role::firstOrCreate([
        'name' => MembershipRole::Owner->value,
        'guard_name' => 'web',
        'tenant_id' => $this->tenant->id,
    ]);

    $user->assignRole($roleModel);

    $this->owner = $user;

    $this->seed(\Database\Seeders\EconomicActivityCodeSeeder::class);
});

it('lista los códigos CIIU seleccionables', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/economic-activity-codes')
        ->assertOk()
        ->assertJsonStructure(['data' => [['code', 'description']]])
        ->assertJsonCount(3125, 'data');
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
