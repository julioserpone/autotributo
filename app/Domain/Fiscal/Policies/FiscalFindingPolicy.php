<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Policies;

use App\Domain\Fiscal\Models\FiscalFinding;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Models\User;

final class FiscalFindingPolicy
{
    public function viewAny(User $user): bool
    {
        return RolePermissions::allows($user, Ability::FiscalDocumentsView);
    }

    public function view(User $user, FiscalFinding $finding): bool
    {
        return $this->viewAny($user) && RolePermissions::sameTenant($finding);
    }

    public function update(User $user, FiscalFinding $finding): bool
    {
        return RolePermissions::allows($user, Ability::FiscalDocumentsReview)
            && RolePermissions::sameTenant($finding);
    }

    public function detect(User $user): bool
    {
        return RolePermissions::allows($user, Ability::FiscalDocumentsReview);
    }
}
