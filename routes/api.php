<?php

declare(strict_types=1);

use App\Domain\Sri\Http\Controllers\SriRobotCallbackController;
use App\Http\Controllers\Api\V1\AuditLogController;
use App\Http\Controllers\Api\V1\AuthController;
use App\Http\Controllers\Api\V1\BroadcastingAuthController;
use App\Http\Controllers\Api\V1\CompanyController;
use App\Http\Controllers\Api\V1\EmailVerificationController;
use App\Http\Controllers\Api\V1\HealthController;
use App\Http\Controllers\Api\V1\InvitationController;
use App\Http\Controllers\Api\V1\PasswordResetController;
use App\Http\Controllers\Api\V1\RealtimeStatusController;
use App\Http\Controllers\Api\V1\SriCredentialController;
use App\Http\Controllers\Api\V1\SyncRunController;
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

        Route::post('/invitations', [InvitationController::class, 'store'])
            ->middleware('auth')
            ->name('api.v1.invitations.store');
        Route::post('/invitations/accept', [InvitationController::class, 'accept'])
            ->name('api.v1.invitations.accept');
    });

// Rutas de negocio tenant-aware. `web` aporta la sesión y CSRF; el contexto
// se inicializa antes de resolver el route model binding para que el scope
// global aplique también a `{company}`.
Route::middleware(['web', 'tenant.context', 'tenant.active', 'auth'])
    ->prefix('v1')
    ->group(function (): void {
        Route::apiResource('companies', CompanyController::class)
            ->names('api.v1.companies');

        // Credenciales del portal SRI (F1-06). El secreto nunca se devuelve y
        // cada lectura o cambio queda auditado en `sri_credential_accesses`.
        Route::get('companies/{company}/credential', [SriCredentialController::class, 'show'])
            ->name('api.v1.companies.credential.show');
        Route::put('companies/{company}/credential', [SriCredentialController::class, 'update'])
            ->name('api.v1.companies.credential.update');
        Route::post('companies/{company}/credential/rotate', [SriCredentialController::class, 'rotate'])
            ->name('api.v1.companies.credential.rotate');
        Route::delete('companies/{company}/credential', [SriCredentialController::class, 'destroy'])
            ->name('api.v1.companies.credential.destroy');

        // Sincronización (F1-06 + F2-03 + F2-09a): usa las credenciales guardadas
        // y la clave de idempotencia del cliente para no duplicar runs. El
        // monitor consulta el listado y los archivos por polling.
        Route::get('sync-runs', [SyncRunController::class, 'index'])
            ->name('api.v1.sync-runs.index');
        Route::post('sync-runs', [SyncRunController::class, 'store'])
            ->name('api.v1.sync-runs.store');
        Route::get('sync-runs/{run}', [SyncRunController::class, 'show'])
            ->name('api.v1.sync-runs.show');
        Route::get('sync-runs/{run}/files', [SyncRunController::class, 'files'])
            ->name('api.v1.sync-runs.files');

        // Historial de auditoría (F1-07), solo lectura y filtrado por tenant.
        Route::get('audit-logs', [AuditLogController::class, 'index'])
            ->name('api.v1.audit-logs.index');

        // Tiempo real (F1-08): autorización de canales privados y estado de la
        // conexión. El polling de F2-09a sigue siendo la fuente de verdad.
        Route::post('broadcasting/auth', BroadcastingAuthController::class)
            ->name('api.v1.broadcasting.auth');
        Route::get('realtime/status', RealtimeStatusController::class)
            ->name('api.v1.realtime.status');
    });
