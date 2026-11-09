<?php

declare(strict_types=1);

namespace App\Domain\Companies\Policies;

use App\Domain\Companies\Models\Company;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Models\User;

/**
 * Autorización de las credenciales del portal.
 *
 * Se autoriza sobre la **empresa** y no sobre la credencial: si todavía no
 * existe, el contador igualmente debe poder crearla. `update` sobre la empresa
 * ya exige `companies.manage`, así que aquí se comprueba el permiso específico
 * y el tenant.
 */
final class SriCredentialPolicy
{
    public function view(User $user, Company $company): bool
    {
        return RolePermissions::allows($user, Ability::CredentialsView)
            && RolePermissions::sameTenant($company);
    }

    public function update(User $user, Company $company): bool
    {
        return RolePermissions::allows($user, Ability::CredentialsManage)
            && RolePermissions::sameTenant($company);
    }

    public function delete(User $user, Company $company): bool
    {
        return $this->update($user, $company);
    }
}
