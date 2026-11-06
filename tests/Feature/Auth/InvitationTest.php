<?php

declare(strict_types=1);

use App\Domain\Tenancy\Actions\InviteUser;
use App\Domain\Tenancy\Models\Invitation;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\Notifications\InvitationNotification;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'test']);
    $this->user = User::factory()->create();

    Role::firstOrCreate([
        'name' => 'owner',
        'guard_name' => 'web',
        'tenant_id' => $this->tenant->id,
    ]);

    Membership::create([
        'tenant_id' => $this->tenant->id,
        'user_id' => $this->user->id,
        'role' => 'owner',
    ]);

    $this->actingAs($this->user);
});

it('crea una invitación y envía el correo', function (): void {
    Notification::fake();

    $this->postJson('/api/v1/auth/invitations', [
        'email' => 'nuevo@autotributo.test',
        'role' => 'accountant',
    ])->assertStatus(201)
        ->assertJsonPath('data.email', 'nuevo@autotributo.test');

    Notification::assertSentOnDemand(
        InvitationNotification::class,
    );
});

it('acepta una invitación y crea el usuario con su rol', function (): void {
    $invitation = InviteUser::class;

    // Crea la invitación directamente para obtener el token.
    $token = bin2hex(random_bytes(32));

    Invitation::create([
        'tenant_id' => $this->tenant->id,
        'invited_by' => $this->user->id,
        'email' => 'aceptado@autotributo.test',
        'role' => 'assistant',
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->addDays(7),
    ]);

    $this->postJson('/api/v1/auth/invitations/accept', [
        'token' => $token,
        'name' => 'Usuario Aceptado',
        'password' => 'clave-segura-123',
        'password_confirmation' => 'clave-segura-123',
    ])->assertStatus(201);

    $invited = User::query()->where('email', 'aceptado@autotributo.test')->first();

    expect($invited)->not->toBeNull()
        ->and($invited->name)->toBe('Usuario Aceptado');

    setPermissionsTeamId($this->tenant->id);
    expect($invited->hasRole('assistant'))->toBeTrue();
});

it('rechaza una invitación expirada', function (): void {
    $token = bin2hex(random_bytes(32));

    Invitation::create([
        'tenant_id' => $this->tenant->id,
        'invited_by' => $this->user->id,
        'email' => 'expirado@autotributo.test',
        'role' => 'assistant',
        'token_hash' => hash('sha256', $token),
        'expires_at' => now()->subDay(),
    ]);

    $this->postJson('/api/v1/auth/invitations/accept', [
        'token' => $token,
        'name' => 'Expirado',
        'password' => 'clave-segura-123',
        'password_confirmation' => 'clave-segura-123',
    ])->assertStatus(422);
});
