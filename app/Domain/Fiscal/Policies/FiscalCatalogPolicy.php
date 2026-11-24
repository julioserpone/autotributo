<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Policies;

use App\Domain\Fiscal\Models\FormLine;
use App\Domain\Fiscal\Models\PersonalExpenseCap;
use App\Domain\Fiscal\Models\RetentionRate;
use App\Domain\Fiscal\Models\TaxRate;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Models\User;

/**
 * Los catálogos fiscales versionados son de solo lectura para la API (F3-09).
 *
 * No hay `create`/`update`: una tarifa o un casillero se publican por
 * despliegue (`catalogs:import`), nunca desde la aplicación, porque un cambio
 * mal aplicado alteraría impuestos ya declarados.
 */
final class FiscalCatalogPolicy
{
    public function viewAny(User $user): bool
    {
        return RolePermissions::allows($user, Ability::FiscalDocumentsView);
    }

    public function view(User $user, TaxRate|RetentionRate|FormLine|PersonalExpenseCap $entry): bool
    {
        return $this->viewAny($user);
    }
}
