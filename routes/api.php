<?php

declare(strict_types=1);

use App\Http\Controllers\Api\V1\HealthController;
use Illuminate\Support\Facades\Route;

// El health check no exige tenant: sirve para liveness/readiness antes de
// que el provisionador haya creado el estudio por defecto.
Route::prefix('v1')->group(function (): void {
    Route::get('/health', HealthController::class)->name('api.v1.health');
});

// Rutas de negocio tenant-aware (F1-03+). El contexto se inicializa antes de
// autenticación y el scope global filtra cada consulta por tenant_id.
Route::middleware(['tenant.context', 'tenant.active'])
    ->prefix('v1')
    ->group(function (): void {
        //
    });
