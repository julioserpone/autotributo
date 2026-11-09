<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'realtime']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->user = User::factory()->create();
    $this->user->assignRole(Role::firstOrCreate([
        'name' => MembershipRole::Owner->value,
        'guard_name' => 'web',
        'tenant_id' => $this->tenant->id,
    ]));

    Membership::query()->create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'role' => MembershipRole::Owner->value,
    ]);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '0990000018001',
    ]);
});

it('autoriza el canal de una empresa del tenant al que pertenece el usuario', function (): void {
    $channel = 'private-tenant.'.$this->tenant->id.'.company.'.$this->company->public_id;

    $response = $this->actingAs($this->user)
        ->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => $channel,
            'socket_id' => '1234.5678',
        ])
        ->assertOk();

    // El payload de autorización de Pusher/Reverb es un objeto con `auth`.
    expect($response->json())->toBeArray()
        ->and($response->json('auth'))->toBeString()
        ->and($response->json('auth'))->toContain('test-key');
});

it('rechaza el canal de una empresa de otro tenant', function (): void {
    $other = Tenant::factory()->create(['slug' => 'realtime-otro']);
    app(TenantContext::class)->initialize($other);

    $foreign = Company::factory()->create([
        'tenant_id' => $other->id,
        'ruc' => '1790012345001',
    ]);

    app(TenantContext::class)->initialize($this->tenant);

    $this->actingAs($this->user)
        ->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-tenant.'.$other->id.'.company.'.$foreign->public_id,
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});

it('rechaza el canal de empresa de un usuario sin membresía', function (): void {
    $outsider = User::factory()->create();

    $this->actingAs($outsider)
        ->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-tenant.'.$this->tenant->id.'.company.'.$this->company->public_id,
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});

it('autoriza solo el canal personal del propio usuario', function (): void {
    $this->actingAs($this->user)
        ->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-tenant.'.$this->tenant->id.'.user.'.$this->user->id,
            'socket_id' => '1234.5678',
        ])
        ->assertOk();

    $this->actingAs($this->user)
        ->postJson('/api/v1/broadcasting/auth', [
            'channel_name' => 'private-tenant.'.$this->tenant->id.'.user.'.($this->user->id + 1),
            'socket_id' => '1234.5678',
        ])
        ->assertForbidden();
});

it('exige autenticación para autorizar canales', function (): void {
    $this->postJson('/api/v1/broadcasting/auth', [
        'channel_name' => 'private-tenant.'.$this->tenant->id.'.company.'.$this->company->public_id,
        'socket_id' => '1234.5678',
    ])->assertUnauthorized();
});

it('expone el estado de la conexión en tiempo real sin secretos', function (): void {
    config([
        'broadcasting.default' => 'reverb',
        'broadcasting.connections.reverb.key' => 'public-key',
        'broadcasting.connections.reverb.options.host' => 'localhost',
        'broadcasting.connections.reverb.options.port' => 8080,
        'broadcasting.connections.reverb.options.scheme' => 'http',
    ]);

    $this->actingAs($this->user)
        ->getJson('/api/v1/realtime/status')
        ->assertOk()
        ->assertJsonPath('data.enabled', true)
        ->assertJsonPath('data.driver', 'reverb')
        ->assertJsonPath('data.key', 'public-key')
        ->assertJsonMissingPath('data.secret');
});

it('marca el tiempo real como deshabilitado cuando el driver no es reverb', function (): void {
    config(['broadcasting.default' => 'null']);

    $this->actingAs($this->user)
        ->getJson('/api/v1/realtime/status')
        ->assertOk()
        ->assertJsonPath('data.enabled', false)
        ->assertJsonPath('data.key', null);
});
