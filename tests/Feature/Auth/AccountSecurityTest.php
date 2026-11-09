<?php

declare(strict_types=1);

use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Tenant::factory()->create(['slug' => 'test']);
    config(['session.driver' => 'database']);
});

it('cambia la contraseña y cierra las demás sesiones', function (): void {
    $user = User::factory()->create(['password' => Hash::make('clave-actual')]);

    // Sesión actual
    $this->actingAs($user)->postJson('/api/v1/auth/password', [
        'current_password' => 'clave-actual',
        'password' => 'clave-nueva-123',
        'password_confirmation' => 'clave-nueva-123',
    ])->assertOk()
        ->assertJsonPath('data.password_changed', true);

    expect(Hash::check('clave-nueva-123', $user->refresh()->password))->toBeTrue();

    // Otra sesión previa debe haberse eliminado
    $otherSessionId = 'otra-sesion';
    DB::table('sessions')->insert([
        'id' => $otherSessionId,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($user)->postJson('/api/v1/auth/password', [
        'current_password' => 'clave-nueva-123',
        'password' => 'clave-final-123',
        'password_confirmation' => 'clave-final-123',
    ])->assertOk();

    expect(DB::table('sessions')->where('id', $otherSessionId)->exists())->toBeFalse();
});

it('rechaza el cambio de contraseña con contraseña actual incorrecta', function (): void {
    $user = User::factory()->create(['password' => Hash::make('clave-actual')]);

    $this->actingAs($user)->postJson('/api/v1/auth/password', [
        'current_password' => 'incorrecta',
        'password' => 'clave-nueva-123',
        'password_confirmation' => 'clave-nueva-123',
    ])->assertStatus(422);
});

it('lista las sesiones activas del usuario', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/auth/sessions');

    $response->assertOk();
    expect($response->json('data'))->toBeArray();
});

it('revoca una sesión ajena y devuelve 404 si no existe', function (): void {
    $user = User::factory()->create();

    $sessionId = 'sesion-a-revocar';
    DB::table('sessions')->insert([
        'id' => $sessionId,
        'user_id' => $user->id,
        'ip_address' => '127.0.0.1',
        'user_agent' => 'PHPUnit',
        'payload' => '',
        'last_activity' => now()->timestamp,
    ]);

    $this->actingAs($user)
        ->deleteJson('/api/v1/auth/sessions/'.$sessionId)
        ->assertNoContent();

    expect(DB::table('sessions')->where('id', $sessionId)->exists())->toBeFalse();

    $this->actingAs($user)
        ->deleteJson('/api/v1/auth/sessions/no-existe')
        ->assertNotFound();
});

it('devuelve el historial de inicios de sesión del usuario', function (): void {
    $user = User::factory()->create();

    $response = $this->actingAs($user)->getJson('/api/v1/auth/login-history');

    $response->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
});
