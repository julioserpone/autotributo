<?php

declare(strict_types=1);

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'test']);
    app(TenantContext::class)->initialize($this->tenant);
});

it('asigna y consulta roles con el tenant como equipo', function (): void {
    $user = User::factory()->create();

    $role = Role::create([
        'name' => 'accountant',
        'guard_name' => 'web',
        'tenant_id' => $this->tenant->id,
    ]);

    $user->assignRole($role);

    expect($user->hasRole('accountant'))->toBeTrue()
        ->and($user->hasRole('owner'))->toBeFalse();
});

it('aisla roles por tenant', function (): void {
    $otherTenant = Tenant::factory()->create();

    $user = User::factory()->create();

    $role = Role::create([
        'name' => 'assistant',
        'guard_name' => 'web',
        'tenant_id' => $this->tenant->id,
    ]);

    $user->assignRole($role);

    app(TenantContext::class)->initialize($otherTenant);

    expect($user->hasRole('assistant'))->toBeFalse();
});
