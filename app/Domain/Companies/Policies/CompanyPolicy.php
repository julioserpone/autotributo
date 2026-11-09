<?php

declare(strict_types=1);

namespace App\Domain\Companies\Policies;

use App\Domain\Companies\Models\Company;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Models\User;

final class CompanyPolicy
{
    public function viewAny(User $user): bool
    {
        return RolePermissions::allows($user, Ability::CompaniesView);
    }

    public function view(User $user, Company $company): bool
    {
        return $this->viewAny($user) && RolePermissions::sameTenant($company);
    }

    public function create(User $user): bool
    {
        return RolePermissions::allows($user, Ability::CompaniesManage);
    }

    public function update(User $user, Company $company): bool
    {
        return RolePermissions::allows($user, Ability::CompaniesManage)
            && RolePermissions::sameTenant($company);
    }

    public function delete(User $user, Company $company): bool
    {
        return $this->archive($user, $company);
    }

    public function archive(User $user, Company $company): bool
    {
        return RolePermissions::allows($user, Ability::CompaniesArchive)
            && RolePermissions::sameTenant($company);
    }

    public function restore(User $user, Company $company): bool
    {
        return $this->archive($user, $company);
    }
}
