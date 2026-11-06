<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

final readonly class AcceptInvitation
{
    public function handle(string $token, string $name, string $password): User
    {
        $invitation = Invitation::query()
            ->where('token_hash', hash('sha256', $token))
            ->first();

        if (! $invitation instanceof Invitation
            || $invitation->accepted_at !== null
            || $invitation->expires_at->isPast()) {
            abort(422, 'La invitación no es válida o ha expirado.');
        }

        app(TenantContext::class)->initialize(
            Tenant::query()->findOrFail($invitation->tenant_id),
        );

        $user = User::query()->firstOrCreate(
            ['email' => $invitation->email],
            [
                'name' => $name,
                'password' => Hash::make($password),
                'email_verified_at' => now(),
            ],
        );

        if ($user->wasRecentlyCreated === false) {
            $user->forceFill(['name' => $name])->save();
        }

        Membership::firstOrCreate(
            [
                'tenant_id' => $invitation->tenant_id,
                'user_id' => $user->id,
            ],
            [
                'role' => $invitation->role,
            ],
        );

        $role = Role::firstOrCreate([
            'name' => $invitation->role,
            'guard_name' => 'web',
            'tenant_id' => $invitation->tenant_id,
        ]);

        if (! $user->hasRole($invitation->role)) {
            $user->assignRole($role);
        }

        $invitation->accepted_at = now();
        $invitation->save();

        return $user;
    }
}
