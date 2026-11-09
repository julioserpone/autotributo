<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Broadcast;

/*
|--------------------------------------------------------------------------
| Canales privados (F1-08)
|--------------------------------------------------------------------------
|
| Los eventos son notificaciones, no fuente de verdad: el cliente siempre
| reconcilia con polling (F2-09a). Aun así, un canal mal autorizado filtraría
| progreso de otra empresa, así que la pertenencia se verifica aquí y no en el
| cliente.
|
| Formato: `private-tenant.{tenantId}.company.{companyId}` y
| `private-tenant.{tenantId}.user.{userId}` (docs/01 §10).
|
*/

Broadcast::channel('tenant.{tenantId}.company.{companyId}', function (User $user, string $tenantId, string $companyId): bool {
    $tenant = app(TenantContext::class)->current();

    if ($tenant === null || (string) $tenant->getTenantKey() !== $tenantId) {
        return false;
    }

    // La membresía es la fuente de pertenencia al tenant; el rol vive en Spatie.
    $isMember = Membership::query()
        ->where('tenant_id', $tenant->getTenantKey())
        ->where('user_id', $user->getKey())
        ->exists();

    if (! $isMember) {
        return false;
    }

    return Company::query()->where('public_id', $companyId)->exists();
});

Broadcast::channel('tenant.{tenantId}.user.{userId}', function (User $user, string $tenantId, string $userId): bool {
    $tenant = app(TenantContext::class)->current();

    if ($tenant === null || (string) $tenant->getTenantKey() !== $tenantId) {
        return false;
    }

    if ((string) $user->getKey() !== $userId) {
        return false;
    }

    return Membership::query()
        ->where('tenant_id', $tenant->getTenantKey())
        ->where('user_id', $user->getKey())
        ->exists();
});
