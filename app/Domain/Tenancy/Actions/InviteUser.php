<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Exceptions\UserAlreadyInTenant;
use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Notifications\InvitationNotification;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

final readonly class InviteUser
{
    /**
     * @return array{invitation: Invitation, resent: bool}
     */
    public function handle(Tenant $tenant, User $inviter, string $email, string $role): array
    {
        app(TenantContext::class)->initialize($tenant);

        $existingUser = User::query()->where('email', $email)->first();

        if ($existingUser instanceof User
            && Membership::query()
                ->where('tenant_id', $tenant->id)
                ->where('user_id', $existingUser->id)
                ->exists()) {
            throw new UserAlreadyInTenant($email);
        }

        Role::firstOrCreate([
            'name' => $role,
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);

        $resent = Invitation::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->where('expires_at', '>', now())
            ->exists();

        // Anula invitaciones pendientes anteriores para que solo la última
        // sea válida: si alguien intenta aceptar la anterior, verá el mensaje
        // de invitación anulada/expirada.
        Invitation::query()
            ->where('tenant_id', $tenant->id)
            ->where('email', $email)
            ->whereNull('accepted_at')
            ->update(['expires_at' => now()]);

        $token = bin2hex(random_bytes(32));

        $invitation = Invitation::create([
            'tenant_id' => $tenant->id,
            'invited_by' => $inviter->id,
            'email' => $email,
            'role' => $role,
            'token_hash' => hash('sha256', $token),
            'expires_at' => now()->addDays(7),
        ]);

        Notification::route('mail', $email)->notify(
            new InvitationNotification($token, $tenant->name, $inviter->name, $inviter->email),
        );

        return ['invitation' => $invitation, 'resent' => $resent];
    }
}