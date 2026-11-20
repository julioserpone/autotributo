<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Companies\Actions\CreateCompany;
use App\Domain\Companies\Actions\Credentials\StoreSriCredential;
use App\Domain\Companies\Models\Company;
use App\Domain\Shared\Values\Ruc;
use App\Domain\Tenancy\Actions\EnsureTenantRoles;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Datos de demo para staging y desarrollo local.
 *
 * Crea (o reutiliza) el tenant por defecto, sus cuatro roles operativos y un
 * usuario por cada rol operativo (owner, accountant, assistant). Si el entorno
 * aporta `DEMO_RUC` y `DEMO_SRI_PASSWORD`, crea además una empresa de prueba
 * con su credencial del portal SRI cifrada ("credenciales selladas"), vinculada
 * al tenant y, por tanto, a los usuarios de prueba.
 *
 * Es idempotente: puede ejecutarse varias veces sin duplicar filas.
 */
final class DemoTenantSeeder extends Seeder
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
        app(EnsureTenantRoles::class)->handle($tenant);

        $userPassword = (string) config('demo.user_password', 'password');

        $owner = null;

        foreach (self::USERS as $role => $email) {
            $user = User::query()->firstOrCreate(
                ['email' => $email],
                [
                    'name' => ucfirst($role).' de prueba',
                    'password' => Hash::make($userPassword),
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

            if ($role === 'owner') {
                $owner = $user;
            }
        }

        $ruc = (string) (config('demo.ruc') ?? '');
        $sriPassword = (string) (config('demo.sri_password') ?? '');

        if ($ruc === '' || $sriPassword === '') {
            if ($this->command !== null) {
                $this->command->info('DemoTenantSeeder: sin DEMO_RUC/DEMO_SRI_PASSWORD, no se creó empresa de prueba.');
            }

            return;
        }

        if (Ruc::tryFrom($ruc) === null) {
            throw new \RuntimeException("DEMO_RUC inválido (no pasa la validación del RUC ecuatoriano): {$ruc}");
        }

        if (! $owner instanceof User) {
            throw new \RuntimeException('DemoTenantSeeder: no se pudo resolver el usuario owner.');
        }

        $company = Company::query()
            ->where('tenant_id', $tenant->id)
            ->where('ruc', $ruc)
            ->first();

        if (! $company instanceof Company) {
            $company = app(CreateCompany::class)->handle([
                'tenant_id' => $tenant->id,
                'ruc' => $ruc,
                'legal_name' => 'Empresa de pruebas',
                'trade_name' => 'Empresa de pruebas',
                'regime' => 'general',
                'is_accounting_required' => true,
                'is_withholding_agent' => false,
                'fiscal_year_start_month' => 1,
                'settings' => [],
            ], $owner);
        }

        app(StoreSriCredential::class)->handle(
            $company,
            $ruc,
            $sriPassword,
            $owner->getKey(),
        );

        if ($this->command !== null) {
            $this->command->info("DemoTenantSeeder: empresa de prueba creada (RUC {$ruc}) con credencial SRI sellada.");
        }
    }
}
