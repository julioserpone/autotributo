<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Bloquea el acceso a endpoints de negocio mientras el owner no haya
 * confirmado su segundo factor (F1-03f). Solo permite los endpoints de
 * configuración de 2FA, logout y perfil.
 */
final class EnsureTwoFactorSetupComplete
{
    /**
     * @var list<string>
     */
    private const ALLOWED_PATHS = [
        'api/v1/auth/two-factor/enable',
        'api/v1/auth/two-factor/confirm',
        'api/v1/auth/two-factor/disable',
        'api/v1/auth/two-factor/recovery-codes',
        'api/v1/auth/two-factor/challenge',
        'api/v1/auth/logout',
        'api/v1/auth/me',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->session()->get('auth.two_factor.setup_required', false)) {
            return $next($request);
        }

        $path = trim($request->path(), '/');

        if (in_array($path, self::ALLOWED_PATHS, true)) {
            return $next($request);
        }

        abort(403, 'Debe configurar el segundo factor antes de continuar.');
    }
}
