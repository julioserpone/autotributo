<?php

declare(strict_types=1);

namespace App\Domain\Tenancy;

use App\Domain\Tenancy\Exceptions\TenantContextNotSet;
use App\Domain\Tenancy\Exceptions\TenantNotFound;
use App\Domain\Tenancy\Models\Tenant;
use Closure;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;
use Stancl\Tenancy\Tenancy;

/**
 * Punto único para leer y cambiar el tenant activo. Envuelve el estado de
 * `stancl/tenancy` para que la aplicación no dependa de sus helpers y para
 * poder probar el contexto sin tocar HTTP.
 */
final class TenantContext
{
    public function __construct(private readonly Tenancy $tenancy) {}

    public function isInitialized(): bool
    {
        return $this->tenancy->initialized;
    }

    public function current(): ?TenantContract
    {
        $tenant = $this->tenancy->tenant;

        return $tenant instanceof TenantContract ? $tenant : null;
    }

    public function tenant(): TenantContract
    {
        $tenant = $this->current();

        if ($tenant === null) {
            throw new TenantContextNotSet('No hay un tenant activo en el contexto actual.');
        }

        return $tenant;
    }

    public function id(): int
    {
        return (int) $this->tenant()->getTenantKey();
    }

    public function initialize(TenantContract $tenant): void
    {
        $this->tenancy->initialize($tenant);
    }

    public function initializeDefault(): Tenant
    {
        $tenant = $this->defaultTenant();
        $this->initialize($tenant);

        return $tenant;
    }

    public function defaultTenant(): Tenant
    {
        $slug = (string) config('tenancy.default_slug');

        $tenant = Tenant::query()->where('slug', $slug)->first();

        if (! $tenant instanceof Tenant) {
            throw TenantNotFound::forSlug($slug);
        }

        return $tenant;
    }

    public function end(): void
    {
        $this->tenancy->end();
    }

    /**
     * Ejecuta el callback con el tenant indicado y restaura el contexto previo.
     *
     * @param  Closure(TenantContract): mixed  $callback
     */
    public function run(TenantContract $tenant, Closure $callback): mixed
    {
        $previous = $this->current();

        $this->initialize($tenant);

        try {
            return $callback($tenant);
        } finally {
            if ($previous instanceof TenantContract) {
                $this->initialize($previous);
            } else {
                $this->end();
            }
        }
    }
}
