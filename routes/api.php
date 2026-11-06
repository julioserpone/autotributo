<?php

declare(strict_types=1);

use App\Domain\Sri\Http\Controllers\SriRobotCallbackController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\TwoFactorController;
use Illuminate\Support\Facades\Route;

// El health check no exige tenant: sirve para liveness/readiness antes de
// que el provisionador haya creado el estudio por defecto.
Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class)->name('api.v1.health');

    // Callback firmado del robot SRI (F2-04). No usa sesión ni contexto de
    // tenant: la autenticación es la firma HMAC verificada por el middleware.
    Route::post('/webhooks/sri-robot', SriRobotCallbackController::class)
        ->middleware('sri.callback')
        ->name('api.v1.webhooks.sri-robot');
});

// Autenticación por sesión (F1-03). `web` aporta la sesión y CSRF; el
// contexto de tenant se inicializa para que los scopes globales funcionen.
Route::middleware(['web', 'tenant.context', 'tenant.active'])
    ->prefix('v1/auth')
    ->group(function (): void {
        Route::post('/login', [AuthController::class, 'login'])
            ->name('api.v1.auth.login');
        Route::post('/logout', [AuthController::class, 'logout'])
            ->middleware('auth')
            ->name('api.v1.auth.logout');
        Route::get('/me', [AuthController::class, 'me'])
            ->middleware('auth')
            ->name('api.v1.auth.me');

        Route::post('/forgot-password', [PasswordResetController::class, 'forgot'])
            ->name('api.v1.auth.forgot-password');
        Route::post('/reset-password', [PasswordResetController::class, 'reset'])
            ->name('api.v1.auth.reset-password');

        Route::post('/email/verification-notification', [EmailVerificationController::class, 'send'])
            ->middleware('auth')
            ->name('api.v1.auth.verification.send');
        Route::get('/email/verify/{id}/{hash}', [EmailVerificationController::class, 'verify'])
            ->middleware(['auth', 'signed', 'throttle:6,1'])
            ->name('verification.verify');

        Route::post('/two-factor/enable', [TwoFactorController::class, 'enable'])
            ->middleware('auth')
            ->name('api.v1.auth.two-factor.enable');
        Route::post('/two-factor/confirm', [TwoFactorController::class, 'confirm'])
            ->middleware('auth')
            ->name('api.v1.auth.two-factor.confirm');
        Route::post('/two-factor/disable', [TwoFactorController::class, 'disable'])
            ->middleware('auth')
            ->name('api.v1.auth.two-factor.disable');
        Route::post('/two-factor/recovery-codes', [TwoFactorController::class, 'recoveryCodes'])
            ->middleware('auth')
            ->name('api.v1.auth.two-factor.recovery-codes');
        Route::post('/two-factor/challenge', [TwoFactorController::class, 'challenge'])
            ->name('api.v1.auth.two-factor.challenge');
    });

// Rutas de negocio tenant-aware (F1-03+). El contexto se inicializa antes de
// autenticación y el scope global filtra cada consulta por tenant_id.
Route::middleware(['tenant.context', 'tenant.active'])
    ->prefix('v1')
    ->group(function (): void {
        //
    });
