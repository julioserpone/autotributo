<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Jobs\Concerns;

use App\Domain\Tenancy\Exceptions\TenantContextNotSet;
use App\Domain\Tenancy\TenantContext;

/**
 * Contrato para jobs que deben ejecutarse con un tenant activo.
 *
 * El `QueueTenancyBootstrapper` transporta el `tenant_id` en el payload y
 * vuelve a inicializar el contexto en el worker. Este trait verifica que el
 * contexto exista: un job despachado sin tenant falla de forma ruidosa.
 *
 * Los jobs que lo usan implementan `handleInTenant()` en lugar de `handle()`.
 *
 * @phpstan-ignore trait.unused
 */
trait TenantAware
{
    public function handle(): void
    {
        $context = app(TenantContext::class);

        if (! $context->isInitialized()) {
            throw TenantContextNotSet::forJob(static::class);
        }

        $this->handleInTenant($context);
    }

    abstract protected function handleInTenant(TenantContext $context): void;
}
