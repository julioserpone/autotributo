<?php

declare(strict_types=1);

use App\Domain\Companies\Exceptions\SriCredentialsMissing;
use App\Domain\Sri\Exceptions\SriRateLimitExceeded;
use App\Domain\Sri\Exceptions\SriSyncAlreadyRunning;
use App\Domain\Sri\Http\Middleware\VerifySriRobotCallback;
use App\Domain\Tenancy\Http\Middleware\EnsureTenantIsActive;
use App\Domain\Tenancy\Http\Middleware\InitializeTenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withSchedule(function (Schedule $schedule): void {
        $schedule->command('sri:reconcile-runs')
            ->everyTenMinutes()
            ->withoutOverlapping();
    })
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->alias([
            'tenant.context' => InitializeTenantContext::class,
            'tenant.active' => EnsureTenantIsActive::class,
            'sri.callback' => VerifySriRobotCallback::class,
            'role' => RoleMiddleware::class,
            'permission' => PermissionMiddleware::class,
            'role_or_permission' => RoleOrPermissionMiddleware::class,
        ]);

        // El tenant debe inicializarse antes de que `SubstituteBindings`
        // resuelva `{company}`: el scope global necesita el contexto activo.
        $middleware->prependToPriorityList(
            SubstituteBindings::class,
            InitializeTenantContext::class,
        );

        $middleware->appendToPriorityList(
            InitializeTenantContext::class,
            EnsureTenantIsActive::class,
        );
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        // F1-06: errores de dominio con un status honesto. El cuerpo se
        // normaliza a `problem+json` en F1-09.
        $exceptions->render(function (SriCredentialsMissing $e): JsonResponse {
            return response()->json(['message' => $e->getMessage()], 409);
        });

        $exceptions->render(function (SriRateLimitExceeded $e): JsonResponse {
            return response()
                ->json(['message' => $e->getMessage()], 429)
                ->header('Retry-After', (string) $e->retryAfterSeconds);
        });

        $exceptions->render(function (SriSyncAlreadyRunning $e): JsonResponse {
            return response()->json([
                'message' => $e->getMessage(),
                'existing_run_id' => $e->runPublicId,
            ], 409);
        });
    })->create();
