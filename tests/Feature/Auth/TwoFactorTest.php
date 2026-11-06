<?php

declare(strict_types=1);

use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use PragmaRX\Google2FA\Google2FA;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Tenant::factory()->create(['slug' => 'test']);
});

function twoFactorUser(): User
{
    return User::factory()->create([
        'password' => Hash::make('secret-password'),
    ]);
}

it('genera un secreto y una URL de configuración', function (): void {
    $user = twoFactorUser();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/two-factor/enable')
        ->assertOk()
        ->assertJsonStructure(['data' => ['secret', 'otpauth_url']]);
});

it('confirma el segundo factor y devuelve códigos de recuperación', function (): void {
    $user = twoFactorUser();

    $enable = $this->actingAs($user)->postJson('/api/v1/auth/two-factor/enable');
    $secret = $enable->json('data.secret');

    $code = (new Google2FA)->getCurrentOtp($secret);

    $this->actingAs($user)
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => $code])
        ->assertOk()
        ->assertJsonStructure(['data' => ['recovery_codes']]);

    expect($user->refresh()->hasTwoFactorEnabled())->toBeTrue();
});

it('rechaza un código de confirmación inválido', function (): void {
    $user = twoFactorUser();

    $this->actingAs($user)->postJson('/api/v1/auth/two-factor/enable');

    $this->actingAs($user)
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => '000000'])
        ->assertStatus(422);
});

it('exige el segundo factor al iniciar sesión cuando está configurado', function (): void {
    $user = twoFactorUser();

    $enable = $this->actingAs($user)->postJson('/api/v1/auth/two-factor/enable');
    $secret = $enable->json('data.secret');
    $code = (new Google2FA)->getCurrentOtp($secret);

    $this->actingAs($user)
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => $code]);

    $this->postJson('/api/v1/auth/logout');

    // Login inicial devuelve 202 y no autentica todavía.
    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertStatus(202)
        ->assertJsonPath('data.two_factor_required', true);

    $this->assertGuest();
});

it('completa el desafío con un código válido', function (): void {
    $user = twoFactorUser();

    $enable = $this->actingAs($user)->postJson('/api/v1/auth/two-factor/enable');
    $secret = $enable->json('data.secret');
    $code = (new Google2FA)->getCurrentOtp($secret);

    $this->actingAs($user)
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => $code]);

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertStatus(202);

    $challengeCode = (new Google2FA)->getCurrentOtp($secret);

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'code' => $challengeCode,
    ])->assertOk();

    $this->assertAuthenticatedAs($user);
});

it('permite usar un código de recuperación una sola vez', function (): void {
    $user = twoFactorUser();

    $enable = $this->actingAs($user)->postJson('/api/v1/auth/two-factor/enable');
    $secret = $enable->json('data.secret');
    $code = (new Google2FA)->getCurrentOtp($secret);

    $confirm = $this->actingAs($user)
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => $code]);

    $recoveryCode = $confirm->json('data.recovery_codes.0');

    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertStatus(202);

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'recovery_code' => $recoveryCode,
    ])->assertOk();

    $this->assertAuthenticatedAs($user);

    // El mismo código ya no sirve.
    $this->postJson('/api/v1/auth/login', [
        'email' => $user->email,
        'password' => 'secret-password',
    ])->assertStatus(202);

    $this->postJson('/api/v1/auth/two-factor/challenge', [
        'recovery_code' => $recoveryCode,
    ])->assertStatus(422);
});

it('deshabilita el segundo factor', function (): void {
    $user = twoFactorUser();

    $enable = $this->actingAs($user)->postJson('/api/v1/auth/two-factor/enable');
    $secret = $enable->json('data.secret');
    $code = (new Google2FA)->getCurrentOtp($secret);

    $this->actingAs($user)
        ->postJson('/api/v1/auth/two-factor/confirm', ['code' => $code]);

    $this->actingAs($user)
        ->postJson('/api/v1/auth/two-factor/disable')
        ->assertOk();

    expect($user->refresh()->hasTwoFactorEnabled())->toBeFalse();
});
