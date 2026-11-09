<?php

declare(strict_types=1);

use App\Domain\Sri\Http\Middleware\VerifySriRobotCallback;
use App\Domain\Tenancy\Http\Middleware\EnsureTenantIsActive;
use App\Domain\Tenancy\Http\Middleware\InitializeTenantContext;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
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
    })->create();
