<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

/**
 * Crea un tenant de prueba con un usuario por cada rol operativo.
 *
 * Pensado para desarrollo local: permite ingresar con cualquiera de las
 * cuentas y verificar permisos/flujo de autenticación sin depender del
 * provisionador completo (F1-10).
 */
final class AuthTestUsersSeeder extends Seeder
{
    /**
     * @var array<string, string>
     */
    private const USERS = [
        'owner' => 'owner@autotributo.test',
        'accountant' => 'accountant@autotributo.test',
        'assistant' => 'assistant@autotributo.test',
    ];

    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => (string) config('tenancy.default_slug')],
            [
                'name' => (string) config('app.name', 'Autotributo'),
                'status' => Tenant::STATUS_ACTIVE,
                'plan' => 'pro',
                'settings' => [],
            ],
        );

        app(TenantContext::class)->initialize($tenant);

        foreach (['owner', 'accountant', 'assistant', 'viewer'] as $roleName) {
            Role::firstOrCreate([
                'name' => $roleName,
                'guard_name' => 'web',
                'tenant_id' => $tenant->id,
            ]);
        }

        foreach (self::USERS as $role => $email) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => ucfirst($role).' de prueba',
                    'password' => Hash::make('password'),
                    'email_verified_at' => now(),
                ],
            );

            $user->assignRole($role);

            Membership::firstOrCreate(
                [
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                ],
                [
                    'role' => $role,
                ],
            );
        }
    }
}
