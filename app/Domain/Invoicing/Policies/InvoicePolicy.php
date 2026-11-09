<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Policies;

use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Models\User;

final class InvoicePolicy
{
    public function viewAny(User $user): bool
    {
        return RolePermissions::allows($user, Ability::InvoicesView);
    }

    public function view(User $user, Invoice $invoice): bool
    {
        return $this->viewAny($user) && RolePermissions::sameTenant($invoice);
    }

    public function update(User $user, Invoice $invoice): bool
    {
        return RolePermissions::allows($user, Ability::InvoicesReview)
            && RolePermissions::sameTenant($invoice);
    }

    public function export(User $user): bool
    {
        return RolePermissions::allows($user, Ability::InvoicesExport);
    }

    public function delete(User $user, Invoice $invoice): bool
    {
        return false;
    }
}
