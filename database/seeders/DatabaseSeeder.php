<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     *
     * Los datos de demo se añaden en F1-10 con `DemoTenantSeeder`.
     */
    public function run(): void
    {
        $this->call(DefaultTenantSeeder::class);
    }
}
