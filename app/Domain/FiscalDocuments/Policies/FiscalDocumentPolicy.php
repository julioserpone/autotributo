<?php

declare(strict_types=1);

namespace App\Domain\FiscalDocuments\Policies;

use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Models\User;

final class FiscalDocumentPolicy
{
    public function viewAny(User $user): bool
    {
        return RolePermissions::allows($user, Ability::FiscalDocumentsView);
    }

    public function view(User $user, FiscalDocument $fiscal_document): bool
    {
        return $this->viewAny($user) && RolePermissions::sameTenant($fiscal_document);
    }

    public function update(User $user, FiscalDocument $fiscal_document): bool
    {
        return RolePermissions::allows($user, Ability::FiscalDocumentsReview)
            && RolePermissions::sameTenant($fiscal_document);
    }

    public function export(User $user): bool
    {
        return RolePermissions::allows($user, Ability::FiscalDocumentsExport);
    }

    public function delete(User $user, FiscalDocument $fiscal_document): bool
    {
        return false;
    }
}
