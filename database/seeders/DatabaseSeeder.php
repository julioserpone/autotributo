<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        $this->call(DefaultTenantSeeder::class);
        $this->call(FiscalCatalogSeeder::class);
        $this->call(ExpenseCategorySeeder::class);

        if (app()->environment(['local', 'testing']) || (bool) config('demo.seed')) {
            $this->call(DemoTenantSeeder::class);
            $this->call(ClassificationScenarioSeeder::class);
        }
    }
}
