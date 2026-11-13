<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Fiscal\Actions\SyncFiscalCatalogs;
use App\Domain\Tenancy\Data\ProvisionResult;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use RuntimeException;
use Spatie\Permission\Models\Role;

/**
 * Provisión inicial idempotente de un estudio.
 *
 * Crea (o reutiliza) el tenant, sus roles, el usuario owner y la membresía.
 * En la primera ejecución envía un enlace de establecimiento de contraseña.
 * Los catálogos fiscales globales se sincronizan de forma idempotente.
 */
final readonly class ProvisionTenant
{
    public function __construct(
        private EnsureTenantRoles $ensureTenantRoles,
        private SyncFiscalCatalogs $syncFiscalCatalogs,
    ) {}

    public function handle(
        string $name,
        string $slug,
        string $ownerEmail,
        ?string $ownerName = null,
        bool $resendInvite = false,
        bool $sendInvite = true,
    ): ProvisionResult {
        $tenant = Tenant::query()->where('slug', $slug)->first();
        $tenantCreated = false;

        if (! $tenant instanceof Tenant) {
            $tenant = Tenant::create([
                'name' => $name,
                'slug' => $slug,
                'status' => Tenant::STATUS_ACTIVE,
                'plan' => 'pro',
                'settings' => [],
            ]);
            $tenantCreated = true;
        }

        app(TenantContext::class)->initialize($tenant);
        $this->ensureTenantRoles->handle($tenant);
        $catalogs = $this->syncFiscalCatalogs->handle();

        $existingOwner = Membership::query()
            ->with('user')
            ->where('tenant_id', $tenant->id)
            ->where('role', MembershipRole::Owner->value)
            ->first();

        if ($existingOwner instanceof Membership
            && $existingOwner->user instanceof User
            && $existingOwner->user->email !== $ownerEmail) {
            throw new RuntimeException(
                "El estudio ya tiene un owner ({$existingOwner->user->email}); no se puede provisionar con {$ownerEmail}.",
            );
        }

        [$owner, $ownerCreated] = $this->owner($ownerEmail, $ownerName);

        $membership = Membership::query()->firstOrCreate(
            [
                'tenant_id' => $tenant->id,
                'user_id' => $owner->id,
            ],
            [
                'role' => MembershipRole::Owner->value,
            ],
        );

        $role = Role::firstOrCreate([
            'name' => MembershipRole::Owner->value,
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);

        if (! $owner->hasRole($role)) {
            $owner->assignRole($role);
        }

        if ($sendInvite && ($ownerCreated || $resendInvite)) {
            Password::sendResetLink(['email' => $owner->email]);
        }

        return new ProvisionResult(
            tenant: $tenant,
            owner: $owner,
            tenantCreated: $tenantCreated,
            ownerCreated: $ownerCreated,
            membershipCreated: $membership->wasRecentlyCreated,
            catalogs: $catalogs,
        );
    }

    /**
     * @return array{0: User, 1: bool}
     */
    private function owner(string $email, ?string $name): array
    {
        $owner = User::query()->where('email', $email)->first();

        if ($owner instanceof User) {
            return [$owner, false];
        }

        $owner = User::create([
            'name' => $name !== null && $name !== '' ? $name : $email,
            'email' => $email,
            'password' => Str::password(32),
        ]);

        return [$owner, true];
    }
}
