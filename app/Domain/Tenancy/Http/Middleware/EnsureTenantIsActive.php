<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Http\Middleware;

use App\Domain\Tenancy\Exceptions\TenantContextNotSet;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Rechaza requests de estudios suspendidos o cancelados. Debe ejecutarse
 * después de `InitializeTenantContext` y antes de autenticación.
 */
final class EnsureTenantIsActive
{
    public function __construct(private readonly TenantContext $context) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->context->isInitialized()) {
            throw TenantContextNotSet::forRequest($request->path());
        }

        $tenant = $this->context->tenant();

        if (! $tenant instanceof Tenant || ! $tenant->isActive()) {
            abort(403, 'El estudio está suspendido o cancelado.');
        }

        return $next($request);
    }
}
