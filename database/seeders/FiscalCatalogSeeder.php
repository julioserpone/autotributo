<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Fiscal\Actions\SyncFiscalCatalogs;
use App\Domain\Fiscal\Data\CatalogSyncResult;
use Illuminate\Database\Seeder;

/**
 * Carga los catálogos fiscales versionados sin duplicar filas.
 */
final class FiscalCatalogSeeder extends Seeder
{
    public function run(): void
    {
        $result = app(SyncFiscalCatalogs::class)->handle();

        $this->report($result);
    }

    private function report(CatalogSyncResult $result): void
    {
        $this->command?->line(sprintf(
            'Catálogos fiscales: %d filas (%d creadas, %d actualizadas, %d sin cambios).',
            $result->total(),
            $result->created(),
            $result->updated(),
            $result->unchanged(),
        ));
    }
}
