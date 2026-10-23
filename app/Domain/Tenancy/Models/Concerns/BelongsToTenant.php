<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models\Concerns;

use App\Domain\Tenancy\Models\Scopes\TenantScope;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Aísla un modelo de negocio por `tenant_id`.
 *
 * - Agrega el `TenantScope` global a las consultas.
 * - Rellena `tenant_id` al crear si no se indicó explícitamente.
 *
 * El `tenant_id` explícito se permite para seeders y pruebas de aislamiento;
 * los endpoints nunca deben aceptarlo desde el request.
 *
 * @phpstan-ignore trait.unused
 */
trait BelongsToTenant
{
    public static function bootBelongsToTenant(): void
    {
        static::addGlobalScope(new TenantScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') === null) {
                $model->setAttribute(
                    'tenant_id',
                    app(TenantContext::class)->id(),
                );
            }
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        return $this->belongsTo(config('tenancy.tenant_model'));
    }
}
