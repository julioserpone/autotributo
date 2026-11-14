<?php

declare(strict_types=1);

namespace App\Domain\Classification\Policies;

use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Tenancy\Enums\Ability;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Models\User;

/**
 * Las reglas las administra quien puede definir criterios fiscales.
 *
 * Las reglas globales se pueden consultar, pero no editar desde un tenant:
 * cambiarlas afectaría a todos los estudios.
 */
final class ClassificationRulePolicy
{
    public function viewAny(User $user): bool
    {
        return RolePermissions::allows($user, Ability::RulesManage);
    }

    public function view(User $user, ClassificationRule $rule): bool
    {
        return $this->viewAny($user)
            && ($rule->isGlobal() || RolePermissions::sameTenant($rule));
    }

    public function create(User $user): bool
    {
        return RolePermissions::allows($user, Ability::RulesManage);
    }

    public function update(User $user, ClassificationRule $rule): bool
    {
        return RolePermissions::allows($user, Ability::RulesManage)
            && ! $rule->isGlobal()
            && RolePermissions::sameTenant($rule);
    }

    public function delete(User $user, ClassificationRule $rule): bool
    {
        return $this->update($user, $rule);
    }

    public function restore(User $user, ClassificationRule $rule): bool
    {
        return $this->update($user, $rule);
    }
}
