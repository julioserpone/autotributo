<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Actions;

use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Notifications\InvitationNotification;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

final readonly class InviteUser
{
    public function handle(Tenant $tenant, User $inviter, string $email, string $role): Invitation
    {
        app(TenantContext::class)->initialize($tenant);

        Role::firstOrCreate([
            'name' => $role,
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);

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
            new InvitationNotification($token, $tenant->name),
        );

        return $invitation;
    }
}
