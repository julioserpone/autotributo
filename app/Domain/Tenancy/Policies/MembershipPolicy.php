<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Policies;

use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Models\User;

final class MembershipPolicy
{
    public function viewAny(User $user): bool
    {
        return RolePermissions::allows($user, Ability::MembersView);
    }

    public function invite(User $user): bool
    {
        return RolePermissions::allows($user, Ability::MembersInvite);
    }

    public function update(User $user, Membership $membership): bool
    {
        return RolePermissions::allows($user, Ability::MembersManage)
            && RolePermissions::sameTenant($membership);
    }

    public function delete(User $user, Membership $membership): bool
    {
        return RolePermissions::allows($user, Ability::MembersManage)
            && RolePermissions::sameTenant($membership);
    }
}
