<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Fiscal\Actions\ImportFiscalCatalog;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Importa un catálogo fiscal versionado desde el CSV oficial del SRI (F3-09).
 *
 * La carga es idempotente: repetir el mismo CSV no duplica filas. Antes de
 * escribir, `--dry-run` muestra qué haría; y en cualquier caso se validan
 * columnas y vigencias, de modo que un CSV mal armado se rechaza entero.
 *
 * Uso: `php artisan catalogs:import tax_rates storage/catalogs/iva-2026.csv`
 */
final class ImportFiscalCatalogCommand extends Command
{
    protected $signature = 'catalogs:import
        {catalog : tax_rates | retention_rates | form_lines | personal_expense_caps}
        {file : Ruta del CSV (relativa a la raíz del proyecto o absoluta)}
        {--dry-run : Valida y cuenta sin escribir nada}
        {--validate : Solo valida el CSV y el resultado de la carga}';

    protected $description = 'Importa un catálogo fiscal versionado desde un CSV oficial';

    public function handle(ImportFiscalCatalog $import): int
    {
        $catalog = (string) $this->argument('catalog');
        $file = (string) $this->argument('file');
        $path = $this->resolvePath($file);

        if ($path === null) {
            $this->error("No se encontró el archivo: {$file}");

            return self::FAILURE;
        }

        $contents = (string) file_get_contents($path);

        $dryRun = (bool) $this->option('dry-run') || (bool) $this->option('validate');

        try {
            $result = $import->handle($catalog, $contents, $dryRun);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($this->option('validate')) {
            $this->info(sprintf('%s: CSV válido (%d filas).', $catalog, $result['created'] + $result['updated'] + $result['unchanged']));

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->line(sprintf(
                '[dry-run] se crearían %d y se actualizarían %d (%d sin cambios). No se escribió nada.',
                $result['created'],
                $result['updated'],
                $result['unchanged'],
            ));

            return self::SUCCESS;
        }

        $this->info(sprintf(
            '%s: %d creadas, %d actualizadas, %d sin cambios.',
            $catalog,
            $result['created'],
            $result['updated'],
            $result['unchanged'],
        ));

        return self::SUCCESS;
    }

    private function resolvePath(string $file): ?string
    {
        $candidates = [
            $file,
            base_path($file),
        ];

        foreach ($candidates as $candidate) {
            if (is_file($candidate)) {
                return $candidate;
            }
        }

        return null;
    }
}
