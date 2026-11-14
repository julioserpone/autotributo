<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Classification\Actions\SyncExpenseCategories;
use App\Domain\Fiscal\Data\CatalogSyncResult;
use Illuminate\Database\Seeder;

/**
 * Carga el catálogo de categorías de gasto sin duplicar filas.
 */
final class ExpenseCategorySeeder extends Seeder
{
    public function run(): void
    {
        $result = app(SyncExpenseCategories::class)->handle();

        $this->report($result);
    }

    private function report(CatalogSyncResult $result): void
    {
        $this->command?->line(sprintf(
            'Categorías de gasto: %d filas (%d creadas, %d actualizadas, %d sin cambios).',
            $result->total(),
            $result->created(),
            $result->updated(),
            $result->unchanged(),
        ));
    }
}
