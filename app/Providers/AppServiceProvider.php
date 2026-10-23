<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Tenancy\Exceptions\InvalidTenancyMode;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(TenantContext::class);
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $mode = (string) config('tenancy.mode');

        if (! in_array($mode, ['single', 'multi'], true)) {
            throw InvalidTenancyMode::forMode($mode);
        }

        // Falla ruidosamente en desarrollo y tests ante consultas N+1,
        // atributos inexistentes o asignaciones masivas no permitidas.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
