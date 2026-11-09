<?php

declare(strict_types=1);

use App\Domain\Companies\Exceptions\SriCredentialsMissing;
use App\Domain\Companies\Exceptions\SriCredentialUnavailable;
use App\Domain\Sri\Exceptions\SriRateLimitExceeded;
use App\Domain\Sri\Exceptions\SriSyncAlreadyRunning;
use App\Domain\Sri\Http\Middleware\VerifySriRobotCallback;
use App\Domain\Tenancy\Http\Middleware\EnsureTenantIsActive;
use App\Domain\Tenancy\Http\Middleware\InitializeTenantContext;
use App\Support\Http\ProblemDetails;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\SubstituteBindings;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\Middleware\PermissionMiddleware;
use Spatie\Permission\Middleware\RoleMiddleware;
use Spatie\Permission\Middleware\RoleOrPermissionMiddleware;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;

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

        // F1-09: contrato único de errores en `application/problem+json`
        // (RFC 9457). Los status no cambian; solo el cuerpo.
        $exceptions->render(function (ValidationException $e, Request $request): ?JsonResponse {
            if (! $request->is('api/*')) {
                return null;
            }

            return ProblemDetails::validation($e->errors());
        });

        $exceptions->render(function (AuthenticationException $e, Request $request): ?JsonResponse {
            return $request->is('api/*') ? ProblemDetails::unauthorized() : null;
        });

        $exceptions->render(function (AuthorizationException $e, Request $request): ?JsonResponse {
            return $request->is('api/*') ? ProblemDetails::forbidden() : null;
        });

        $exceptions->render(function (AccessDeniedHttpException $e, Request $request): ?JsonResponse {
            return $request->is('api/*') ? ProblemDetails::forbidden() : null;
        });

        $exceptions->render(function (NotFoundHttpException $e, Request $request): ?JsonResponse {
            return $request->is('api/*') ? ProblemDetails::notFound() : null;
        });

        $exceptions->render(function (ThrottleRequestsException $e, Request $request): ?JsonResponse {
            return $request->is('api/*')
                ? ProblemDetails::tooManyRequests((int) ($e->getHeaders()['Retry-After'] ?? 60))
                : null;
        });

        // Errores de dominio con un status honesto y un `type` estable.
        $exceptions->render(function (SriCredentialsMissing $e): JsonResponse {
            return ProblemDetails::conflict('sri-credentials-missing', $e->getMessage());
        });

        $exceptions->render(function (SriCredentialUnavailable $e): JsonResponse {
            return ProblemDetails::conflict('sri-credential-unavailable', $e->getMessage());
        });

        $exceptions->render(function (SriRateLimitExceeded $e): JsonResponse {
            return ProblemDetails::tooManyRequests($e->retryAfterSeconds);
        });

        $exceptions->render(function (SriSyncAlreadyRunning $e): JsonResponse {
            return ProblemDetails::conflict(
                'sri-sync-already-running',
                $e->getMessage(),
                ['existing_run_id' => $e->runPublicId],
            );
        });
    })->create();
