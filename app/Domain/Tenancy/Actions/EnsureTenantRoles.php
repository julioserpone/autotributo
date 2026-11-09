<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Spatie\Permission\Models\Role;

/**
 * Garantiza que un tenant tenga los cuatro roles operativos.
 *
 * Es idempotente y debe ejecutarse con el tenant activo para que Spatie
 * resuelva el equipo correcto.
 */
final readonly class EnsureTenantRoles
{
    public function handle(Tenant $tenant): void
    {
        app(TenantContext::class)->initialize($tenant);

        foreach (MembershipRole::cases() as $role) {
            Role::firstOrCreate([
                'name' => $role->value,
                'guard_name' => 'web',
                'tenant_id' => $tenant->id,
            ]);
        }
    }
}
