<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'problem']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->owner = User::factory()->create();
    $this->owner->assignRole(Role::firstOrCreate([
        'name' => MembershipRole::Owner->value,
        'guard_name' => 'web',
        'tenant_id' => $this->tenant->id,
    ]));

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '0990000018001',
    ]);
});

it('devuelve 422 en problem+json con los errores por campo', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/companies', ['ruc' => 'invalido'])
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://autotributo.com/problems/validation-failed')
        ->assertJsonPath('status', 422)
        ->assertJsonStructure(['type', 'title', 'status', 'detail', 'errors' => ['ruc']]);
});

it('devuelve 401 en problem+json sin sesión', function (): void {
    $this->getJson('/api/v1/companies')
        ->assertStatus(401)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://autotributo.com/problems/unauthenticated');
});

it('devuelve 404 en problem+json para un recurso inexistente', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/01J0000000000000000000000')
        ->assertStatus(404)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://autotributo.com/problems/not-found');
});

it('devuelve 403 en problem+json cuando falta el permiso', function (): void {
    $viewer = User::factory()->create();
    $viewer->assignRole(Role::firstOrCreate([
        'name' => MembershipRole::Viewer->value,
        'guard_name' => 'web',
        'tenant_id' => $this->tenant->id,
    ]));

    $this->actingAs($viewer)
        ->postJson('/api/v1/companies', ['ruc' => '0602910945001'])
        ->assertStatus(403)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://autotributo.com/problems/forbidden');
});

it('devuelve 409 en problem+json con el run existente', function (): void {
    $this->actingAs($this->owner)->putJson(
        '/api/v1/companies/'.$this->company->public_id.'/credential',
        [
            'username' => '0990000018001',
            'password' => 'secreto-123',
            'password_confirmation' => 'secreto-123',
        ],
    )->assertCreated();

    $payload = [
        'company_id' => $this->company->public_id,
        'periods' => ['2026-08'],
    ];

    $first = $this->actingAs($this->owner)
        ->withHeader('Idempotency-Key', 'problem-1')
        ->postJson('/api/v1/sync-runs', $payload)
        ->assertStatus(202);

    $this->actingAs($this->owner)
        ->withHeader('Idempotency-Key', 'problem-2')
        ->postJson('/api/v1/sync-runs', $payload)
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://autotributo.com/problems/sri-sync-already-running')
        ->assertJsonPath('existing_run_id', $first->json('data.0.id'));
});

it('mantiene el contrato de éxito sin cambios', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/companies')
        ->assertOk()
        ->assertJsonStructure(['data', 'links', 'meta']);
});
