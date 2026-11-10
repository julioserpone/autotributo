<?php

declare(strict_types=1);

use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Tenant::factory()->create(['slug' => 'test']);
});

it('obliga al owner a configurar 2FA y bloquea endpoints de negocio', function (): void {
    $tenant = Tenant::query()->where('slug', 'test')->first();
    app(TenantContext::class)->initialize($tenant);

    $user = User::factory()->create(['password' => Hash::make('secret-password')]);
    $user->assignRole(Role::firstOrCreate(['name' => MembershipRole::Owner->value, 'guard_name' => 'web', 'tenant_id' => $tenant->id]));
    Membership::create(['tenant_id' => $tenant->id, 'user_id' => $user->id, 'role' => 'owner']);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertStatus(202)
        ->assertJsonPath('data.two_factor_setup_required', true);

    $this->postJson('/api/v1/companies', ['ruc' => '0602910945001'])->assertForbidden();

    $this->postJson('/api/v1/auth/two-factor/enable')->assertOk();
});

it('bloquea tras 5 intentos inválidos de 2FA', function (): void {
    $user = User::factory()->create(['password' => Hash::make('secret-password')]);

    $enable = $this->actingAs($user)->postJson('/api/v1/auth/two-factor/enable');
    $enable->assertOk();

    foreach (range(1, 5) as $i) {
        $this->actingAs($user)
            ->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])
            ->assertStatus(422);
    }

    $this->actingAs($user)
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])
        ->assertStatus(429);
});
