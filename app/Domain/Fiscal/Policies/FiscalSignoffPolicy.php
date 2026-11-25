<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Policies;

use App\Domain\Fiscal\Models\FiscalSignoffReview;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Models\User;

final class FiscalSignoffPolicy
{
    public function viewAny(User $user): bool
    {
        return RolePermissions::allows($user, Ability::DeclarationsView);
    }

    public function reviewAny(User $user): bool
    {
        return RolePermissions::allows($user, Ability::FiscalSignoffsReview);
    }

    public function view(User $user, FiscalSignoffReview $review): bool
    {
        return $this->viewAny($user) && RolePermissions::sameTenant($review);
    }
}
