<?php

declare(strict_types=1);

namespace App\Domain\Classification\Models\Scopes;

use App\Domain\Tenancy\Exceptions\TenantContextNotSet;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtra por el tenant activo incluyendo las filas globales.
 *
 * Una regla global (`tenant_id` nulo) aplica a todos los estudios, pero el
 * resultado nunca mezcla filas de dos tenants distintos.
 *
 * @implements Scope<Model>
 */
final class TenantOrGlobalScope implements Scope
{
    /**
     * @param  Builder<covariant Model>  $builder
     */
    public function apply(Builder $builder, Model $model): void
    {
        $context = app(TenantContext::class);

        if (! $context->isInitialized()) {
            throw TenantContextNotSet::forModel($model::class);
        }

        $table = $model->getTable();

        $builder->where(function (Builder $query) use ($table, $context): void {
            $query->where($table.'.tenant_id', $context->id())
                ->orWhereNull($table.'.tenant_id');
        });
    }
}
