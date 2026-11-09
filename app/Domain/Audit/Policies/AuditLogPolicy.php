<?php

declare(strict_types=1);

namespace App\Domain\Audit\Policies;

use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Models\User;

/**
 * Solo `owner` y `accountant` consultan el historial (`audit.view`).
 *
 * El filtro por tenant vive en el controlador porque `AuditLog` no usa
 * `BelongsToTenant`; aquí solo se decide el rol.
 */
final class AuditLogPolicy
{
    public function viewAny(User $user): bool
    {
        return RolePermissions::allows($user, Ability::AuditView);
    }
}
