<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Companies\Models\EconomicActivityCode;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Carga los códigos CIIU seleccionables (último subnivel) del SRI.
 *
 * La fuente es `database/seeders/data/ciiu.csv` (generado desde el clasificador
 * oficial CIIU v4). La carga es idempotente: se hace upsert por `code`.
 */
final class EconomicActivityCodeSeeder extends Seeder
{
    public function run(): void
    {
        $path = database_path('seeders/data/ciiu.csv');

        if (! is_file($path)) {
            $this->command?->warn("EconomicActivityCodeSeeder: no se encontró {$path}.");

            return;
        }

        $handle = fopen($path, 'r');

        if ($handle === false) {
            $this->command?->error("EconomicActivityCodeSeeder: no se pudo abrir {$path}.");

            return;
        }

        $now = now();
        $rows = [];
        $header = fgetcsv($handle);

        while (($line = fgetcsv($handle)) !== false) {
            if (count($line) < 2) {
                continue;
            }

            $code = trim((string) $line[0]);
            $description = trim((string) $line[1]);

            if ($code === '' || $description === '') {
                continue;
            }

            $rows[] = [
                'code' => $code,
                'description' => mb_substr($description, 0, 500),
                'level' => 'SUBNIVEL ACTIVIDAD',
                'created_at' => $now,
                'updated_at' => $now,
            ];

            if (count($rows) >= 500) {
                $this->upsert($rows);
                $rows = [];
            }
        }

        fclose($handle);

        if ($rows !== []) {
            $this->upsert($rows);
        }

        $this->command?->info('EconomicActivityCodeSeeder: códigos CIIU cargados.');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(array $rows): void
    {
        DB::table('economic_activity_codes')->upsert(
            $rows,
            ['code'],
            ['description', 'level', 'updated_at'],
        );
    }
}
