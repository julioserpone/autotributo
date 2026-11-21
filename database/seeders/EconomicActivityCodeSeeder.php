<?php

declare(strict_types=1);

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Carga el catálogo completo de actividades económicas (CIIU v4 del SRI).
 *
 * Solo los nodos hoja (`is_selectable = true`) son seleccionables por el
 * usuario; los niveles superiores se conservan para trazabilidad y futuras
 * consultas jerárquicas.
 *
 * La fuente es `database/seeders/data/ciiu.csv` (generado desde el clasificador
 * oficial). La carga es idempotente: se hace upsert por `code`.
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
            if (count($line) < 4) {
                continue;
            }

            $code = trim((string) $line[0]);
            $description = trim((string) $line[1]);
            $level = trim((string) $line[2]);
            $selectable = trim((string) $line[3]) === '1';

            if ($code === '' || $description === '') {
                continue;
            }

            $rows[] = [
                'code' => $code,
                'description' => mb_substr($description, 0, 500),
                'level' => $level !== '' ? $level : 'SUBNIVEL ACTIVIDAD',
                'is_selectable' => $selectable,
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

        $this->command?->info('EconomicActivityCodeSeeder: catálogo CIIU cargado.');
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function upsert(array $rows): void
    {
        DB::table('economic_activity_codes')->upsert(
            $rows,
            ['code'],
            ['description', 'level', 'is_selectable', 'updated_at'],
        );
    }
}
