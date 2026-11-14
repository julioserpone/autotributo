<?php

declare(strict_types=1);

namespace App\Domain\Classification\Models\Concerns;

use App\Domain\Classification\Models\Scopes\TenantOrGlobalScope;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;

/**
 * Aísla modelos que admiten filas globales (`tenant_id` nulo).
 *
 * Es una variante de `BelongsToTenant` para catálogos compartidos: la consulta
 * solo devuelve las filas del tenant activo o las globales, nunca las de otro
 * tenant. Sigue exigiendo contexto para no filtrar datos por accidente.
 *
 * @phpstan-ignore trait.unused
 */
trait BelongsToTenantOrGlobal
{
    public static function bootBelongsToTenantOrGlobal(): void
    {
        static::addGlobalScope(new TenantOrGlobalScope);

        static::creating(function (Model $model): void {
            if ($model->getAttribute('tenant_id') !== null) {
                return;
            }

            $context = app(TenantContext::class);

            if ($context->isInitialized()) {
                $model->setAttribute('tenant_id', $context->id());
            }
        });
    }
}
