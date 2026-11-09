<?php

declare(strict_types=1);

namespace App\Domain\Sri\Policies;

use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Models\User;

final class SriSyncRunPolicy
{
    public function viewAny(User $user): bool
    {
        return RolePermissions::allows($user, Ability::SyncView);
    }

    public function view(User $user, SriSyncRun $run): bool
    {
        return $this->viewAny($user) && RolePermissions::sameTenant($run);
    }

    public function create(User $user): bool
    {
        return RolePermissions::allows($user, Ability::SyncStart);
    }

    public function retry(User $user, SriSyncRun $run): bool
    {
        return RolePermissions::allows($user, Ability::SyncStart)
            && RolePermissions::sameTenant($run);
    }

    public function cancel(User $user, SriSyncRun $run): bool
    {
        return RolePermissions::allows($user, Ability::SyncCancel)
            && RolePermissions::sameTenant($run);
    }
}
