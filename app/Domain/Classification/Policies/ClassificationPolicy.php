<?php

declare(strict_types=1);

namespace App\Domain\Classification\Policies;

use App\Domain\Classification\Models\Classification;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Models\User;

/**
 * Revisión humana de clasificaciones (F3-05).
 *
 * Consultar la cola requiere ver comprobantes; decidir requiere el permiso de
 * revisión (`fiscal_documents.review`), que no tienen los roles de solo lectura.
 */
final class ClassificationPolicy
{
    public function viewAny(User $user): bool
    {
        return RolePermissions::allows($user, Ability::FiscalDocumentsView);
    }

    public function view(User $user, Classification $classification): bool
    {
        return $this->viewAny($user) && RolePermissions::sameTenant($classification);
    }

    public function review(User $user, Classification $classification): bool
    {
        return RolePermissions::allows($user, Ability::FiscalDocumentsReview)
            && RolePermissions::sameTenant($classification);
    }

    public function reviewAny(User $user): bool
    {
        return RolePermissions::allows($user, Ability::FiscalDocumentsReview);
    }
}
