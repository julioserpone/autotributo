<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models\Scopes;

use App\Domain\Tenancy\Exceptions\TenantContextNotSet;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;

/**
 * Filtra toda consulta por el tenant activo. Si no hay contexto, falla en
 * lugar de devolver datos de todos los estudios.
 *
 * @implements Scope<Model>
 */
final class TenantScope implements Scope
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

        $builder->where(
            $model->getTable().'.tenant_id',
            $context->id(),
        );
    }
}
