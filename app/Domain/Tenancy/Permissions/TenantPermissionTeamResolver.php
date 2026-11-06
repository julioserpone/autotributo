<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Permissions;

use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Model;
use Spatie\Permission\Contracts\PermissionsTeamResolver;
use Throwable;

final class TenantPermissionTeamResolver implements PermissionsTeamResolver
{
    private bool $hasOverride = false;

    private int|string|null $override = null;

    public function getPermissionsTeamId(): int|string|null
    {
        if ($this->hasOverride) {
            return $this->override;
        }

        try {
            return app(TenantContext::class)->id();
        } catch (Throwable) {
            return null;
        }
    }

    public function setPermissionsTeamId(Model|int|string|null $id): void
    {
        $this->override = is_object($id) ? $id->getKey() : $id;
        $this->hasOverride = true;
    }
}
