<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Classification\Catalogs\CsvExpenseCategoryReader;
use App\Domain\Classification\Models\ExpenseCategory;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Importa un árbol de categorías de gasto desde un CSV.
 *
 * Formato: `code,name,parent_code,is_personal_deductible,is_business_deductible,icon,display_order`.
 * Los padres se resuelven por `code`, así que el archivo no depende de IDs.
 *
 * Es idempotente por `code`: repetir el mismo archivo no duplica. A diferencia
 * de las reglas base, una categoría creada por CSV queda **activa** (vigente):
 * es un catálogo operativo, no una sugerencia que deba revisarse antes de usar.
 *
 * Uso: `php artisan categories:import storage/catalogs/expense-categories-base.csv`
 */
final class ImportExpenseCategoriesCommand extends Command
{
    protected $signature = 'categories:import
        {file : Ruta del CSV (relativa a la raíz del proyecto o absoluta)}
        {--dry-run : Valida y cuenta sin escribir nada}';

    protected $description = 'Importa categorías de gasto desde un CSV con parent_code';

    public function handle(CsvExpenseCategoryReader $reader): int
    {
        $file = (string) $this->argument('file');
        $path = is_file($file) ? $file : base_path($file);

        if (! is_file($path)) {
            $this->error("No se encontró el archivo: {$file}");

            return self::FAILURE;
        }

        try {
            $rows = $reader->read((string) file_get_contents($path));
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $dryRun = (bool) $this->option('dry-run');
        $created = 0;
        $updated = 0;
        $unchanged = 0;

        // Dos pasadas: primero los padres, luego las subcategorías, para que
        // `parent_code` siempre encuentre su fila aunque el CSV venga ordenado
        // con los hijos antes que los padres.
        $ordered = array_merge(
            array_values(array_filter($rows, static fn (array $row): bool => ($row['parent_code'] ?? null) === null)),
            array_values(array_filter($rows, static fn (array $row): bool => ($row['parent_code'] ?? null) !== null)),
        );

        foreach ($ordered as $row) {
            $parentId = null;

            if (($row['parent_code'] ?? null) !== null) {
                $parentId = ExpenseCategory::query()->where('code', $row['parent_code'])->value('id');

                if ($parentId === null) {
                    $this->warn("{$row['code']}: el padre {$row['parent_code']} no existe; se omite.");

                    continue;
                }
            }

            $attributes = [
                'name' => $row['name'],
                'parent_id' => $parentId,
                'is_personal_deductible' => (bool) $row['is_personal_deductible'],
                'is_business_deductible' => (bool) $row['is_business_deductible'],
                'icon' => $row['icon'],
                'display_order' => (int) $row['display_order'],
                'valid_from' => $row['valid_from'] ?? '2024-01-01',
                'source' => 'import:csv',
            ];

            $existing = ExpenseCategory::query()->where('code', $row['code'])->first();

            if ($existing instanceof ExpenseCategory) {
                $existing->fill($attributes);

                if ($existing->isDirty()) {
                    if (! $dryRun) {
                        $existing->save();
                    }

                    $updated++;
                } else {
                    $unchanged++;
                }

                continue;
            }

            if (! $dryRun) {
                ExpenseCategory::query()->create(['code' => $row['code'], ...$attributes]);
            }

            $created++;
        }

        if ($dryRun) {
            $this->line(sprintf('[dry-run] se crearían %d, se actualizarían %d y %d están sin cambios.', $created, $updated, $unchanged));
        } else {
            $this->info(sprintf('Categorías de gasto: %d creadas, %d actualizadas, %d sin cambios.', $created, $updated, $unchanged));
        }

        return self::SUCCESS;
    }
}
