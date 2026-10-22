<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        //
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        // Falla ruidosamente en desarrollo y tests ante consultas N+1,
        // atributos inexistentes o asignaciones masivas no permitidas.
        Model::shouldBeStrict(! $this->app->isProduction());
    }
}
