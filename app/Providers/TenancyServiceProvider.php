<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;
use Stancl\Tenancy\Events;
use Stancl\Tenancy\Listeners;

/**
 * Conecta el ciclo de vida de stancl/tenancy con sus bootstrappers.
 *
 * No se registran los listeners de creación/migración de bases de datos por
 * tenant: el MVP usa una sola base de datos y el aislamiento por tenant_id.
 */
class TenancyServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(
            Events\TenancyInitialized::class,
            Listeners\BootstrapTenancy::class,
        );

        Event::listen(
            Events\TenancyEnded::class,
            Listeners\RevertToCentralContext::class,
        );
    }
}
