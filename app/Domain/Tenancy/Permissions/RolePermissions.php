<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Permissions;

use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Throwable;

/**
 * Matriz única de roles y acciones.
 *
 * Las policies consultan esta clase para no repetir reglas por recurso. Los
 * roles viven en Spatie Permission con `tenant_id` como equipo; aquí solo se
 * declara qué puede hacer cada rol dentro del tenant activo.
 */
final class RolePermissions
{
    /**
     * @return list<Ability>
     */
    public static function for(MembershipRole $role): array
    {
        return match ($role) {
            MembershipRole::Owner => Ability::cases(),
            MembershipRole::Accountant => [
                Ability::CompaniesView,
                Ability::CompaniesManage,
                Ability::CompaniesArchive,
                Ability::CredentialsView,
                Ability::CredentialsManage,
                Ability::SyncView,
                Ability::SyncStart,
                Ability::SyncCancel,
                Ability::InvoicesView,
                Ability::InvoicesReview,
                Ability::InvoicesExport,
                Ability::RulesManage,
                Ability::DeclarationsView,
                Ability::DeclarationsApprove,
                Ability::DeclarationsExport,
                Ability::MembersView,
                Ability::MembersInvite,
                Ability::AuditView,
            ],
            MembershipRole::Assistant => [
                Ability::CompaniesView,
                Ability::SyncView,
                Ability::SyncStart,
                Ability::SyncCancel,
                Ability::InvoicesView,
                Ability::InvoicesReview,
                Ability::InvoicesExport,
                Ability::DeclarationsView,
            ],
            MembershipRole::Viewer => [
                Ability::CompaniesView,
                Ability::SyncView,
                Ability::InvoicesView,
                Ability::DeclarationsView,
            ],
        };
    }

    public static function allows(User $user, Ability $ability): bool
    {
        foreach (MembershipRole::cases() as $role) {
            if ($user->hasRole($role->value) && in_array($ability, self::for($role), true)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Comprueba que el recurso pertenece al tenant activo. Es defensa en
     * profundidad: el `TenantScope` ya filtra las consultas, pero una policy
     * nunca debe autorizar un modelo de otro tenant.
     */
    public static function sameTenant(Model $model): bool
    {
        $tenantId = $model->getAttribute('tenant_id');

        if ($tenantId === null) {
            return false;
        }

        try {
            return (int) $tenantId === app(TenantContext::class)->id();
        } catch (Throwable) {
            return false;
        }
    }
}
