<?php

declare(strict_types=1);

use App\Domain\Tenancy\Models\Tenant;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Auth\Notifications\VerifyEmail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\URL;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Tenant::factory()->create(['slug' => 'test']);
});

it('envía el enlace de recuperación de contraseña', function (): void {
    Notification::fake();

    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/forgot-password', [
        'email' => $user->email,
    ])->assertOk();

    Notification::assertSentTo($user, ResetPassword::class);
});

it('restablece la contraseña con un token válido', function (): void {
    $user = User::factory()->create();
    $token = Password::createToken($user);

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => $token,
        'email' => $user->email,
        'password' => 'nueva-clave-123',
        'password_confirmation' => 'nueva-clave-123',
    ])->assertOk();

    expect(Hash::check('nueva-clave-123', $user->refresh()->password))->toBeTrue();
});

it('rechaza un token de recuperación inválido', function (): void {
    $user = User::factory()->create();

    $this->postJson('/api/v1/auth/reset-password', [
        'token' => 'token-invalido',
        'email' => $user->email,
        'password' => 'nueva-clave-123',
        'password_confirmation' => 'nueva-clave-123',
    ])->assertStatus(422);
});

it('envía el correo de verificación', function (): void {
    Notification::fake();

    $user = User::factory()->unverified()->create();

    $this->actingAs($user)
        ->postJson('/api/v1/auth/email/verification-notification')
        ->assertOk();

    Notification::assertSentTo($user, VerifyEmail::class);
});

it('marca el correo como verificado con una URL firmada', function (): void {
    $user = User::factory()->unverified()->create();

    $url = URL::temporarySignedRoute(
        'verification.verify',
        now()->addMinutes(60),
        ['id' => $user->id, 'hash' => sha1($user->getEmailForVerification())],
    );

    $this->actingAs($user)->get($url)->assertOk();

    expect($user->refresh()->hasVerifiedEmail())->toBeTrue();
});
