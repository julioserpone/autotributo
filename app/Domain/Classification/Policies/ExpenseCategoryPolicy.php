<?php

declare(strict_types=1);

namespace App\Domain\Classification\Policies;

use App\Domain\Classification\Models\ExpenseCategory;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Models\User;

/**
 * El catálogo de categorías es de solo lectura para la API.
 */
final class ExpenseCategoryPolicy
{
    public function viewAny(User $user): bool
    {
        return RolePermissions::allows($user, Ability::FiscalDocumentsView);
    }

    public function view(User $user, ExpenseCategory $category): bool
    {
        return $this->viewAny($user);
    }
}
