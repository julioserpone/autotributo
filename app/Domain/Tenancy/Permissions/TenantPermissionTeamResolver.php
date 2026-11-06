<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Permissions;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;
use Throwable;

final class TenantPermissionTeamResolver implements PermissionsTeamResolver
{
    public function getPermissionsTeamId(): ?int
    {
        try {
            return app(TenantContext::class)->id();
        } catch (Throwable) {
            return null;
        }
    }

    public function setPermissionsTeamId(Model|int|string|null $id): void
    {
        // El tenant se resuelve desde el contexto activo; no se guarda estado.
    }
}
