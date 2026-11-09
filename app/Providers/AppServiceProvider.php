<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Audit\Policies\AuditLogPolicy;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Policies\CompanyPolicy;
use App\Domain\Companies\Policies\SriCredentialPolicy;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Policies\InvoicePolicy;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Sri\Policies\SriSyncRunPolicy;
use App\Domain\Tenancy\Exceptions\InvalidTenancyMode;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Policies\MembershipPolicy;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Gate;
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

        Gate::policy(Company::class, CompanyPolicy::class);
        Gate::policy(AuditLog::class, AuditLogPolicy::class);
        Gate::policy(SriSyncRun::class, SriSyncRunPolicy::class);
        Gate::policy(Invoice::class, InvoicePolicy::class);
        Gate::policy(Membership::class, MembershipPolicy::class);

        // Las credenciales se autorizan sobre la empresa, porque todavía pueden
        // no existir cuando el contador las crea por primera vez.
        Gate::define('credentials.view', [SriCredentialPolicy::class, 'view']);
        Gate::define('credentials.update', [SriCredentialPolicy::class, 'update']);
        Gate::define('credentials.delete', [SriCredentialPolicy::class, 'delete']);
    }
}
