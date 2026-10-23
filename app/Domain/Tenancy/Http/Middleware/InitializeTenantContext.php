<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Http\Middleware;

use App\Domain\Tenancy\Exceptions\InvalidTenancyMode;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Inicializa el tenant al comienzo del request.
 *
 * En modo `single` liga el tenant por defecto (`TENANCY_DEFAULT_SLUG`). En
 * modo `multi` el resolver por dominio será el responsable de inicializarlo;
 * el middleware solo valida la configuración.
 */
final class InitializeTenantContext
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        $mode = (string) config('tenancy.mode');

        if (! in_array($mode, ['single', 'multi'], true)) {
            throw InvalidTenancyMode::forMode($mode);
        }

        if ($mode !== 'single') {
            return $next($request);
        }

        $hadContext = $this->context->isInitialized();

        if (! $hadContext) {
            $this->context->initializeDefault();
        }

        try {
            return $next($request);
        } finally {
            if (! $hadContext) {
                $this->context->end();
            }
        }
    }
}
