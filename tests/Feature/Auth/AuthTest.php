<?php

declare(strict_types=1);

use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Http\Middleware\ValidateCsrfToken;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'test']);
    $this->user = User::factory()->create([
        'password' => Hash::make('secret-password'),
    ]);

    Membership::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'role' => 'assistant',
    ]);
});

it('inicia sesión con credenciales válidas', function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $response = $this->postJson('/api/v1/auth/login', [
        'email' => $this->user->email,
        'password' => 'secret-password',
    ]);

    $response->assertOk()
        ->assertJsonPath('data.email', $this->user->email);

    $this->assertAuthenticatedAs($this->user);
});

it('rechaza credenciales inválidas', function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->postJson('/api/v1/auth/login', [
        'email' => $this->user->email,
        'password' => 'wrong-password',
    ])->assertStatus(422);

    $this->assertGuest();
});

it('devuelve el usuario autenticado en /me', function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->actingAs($this->user)->getJson('/api/v1/auth/me')->assertOk()
        ->assertJsonPath('data.id', $this->user->id);
});

it('cierra la sesión', function (): void {
    $this->withoutMiddleware(ValidateCsrfToken::class);

    $this->actingAs($this->user)->postJson('/api/v1/auth/logout')->assertOk();

    $this->assertGuest();
});
