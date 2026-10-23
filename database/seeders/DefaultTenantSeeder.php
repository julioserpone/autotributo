<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Seeder;

/**
 * Crea el tenant que resuelve `TENANCY_MODE=single` en cada request.
 * F1-10 sustituye este seeder por el comando `app:provision`, que además
 * crea el estudio, el usuario administrador y los datos demo.
 */
final class DefaultTenantSeeder extends Seeder
{
    public function run(): void
    {
        Tenant::query()->firstOrCreate(
            ['slug' => (string) config('tenancy.default_slug')],
            [
                'name' => (string) config('app.name', 'Autotributo'),
                'status' => Tenant::STATUS_ACTIVE,
                'plan' => 'pro',
                'settings' => [],
            ],
        );
    }
}
