<?php

declare(strict_types=1);

use App\Domain\Sri\Http\Controllers\SriRobotCallbackController;
use App\Http\Controllers\Api\V1\HealthController;
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

// Rutas de negocio tenant-aware (F1-03+). El contexto se inicializa antes de
// autenticación y el scope global filtra cada consulta por tenant_id.
Route::middleware(['tenant.context', 'tenant.active'])
    ->prefix('v1')
    ->group(function (): void {
        //
    });
