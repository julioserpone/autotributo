<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Actions;

use App\Domain\Fiscal\Catalogs\FiscalCatalogData;
use App\Domain\Fiscal\Data\CatalogSyncResult;
use App\Domain\Fiscal\Models\FormLine;
use App\Domain\Fiscal\Models\PersonalExpenseCap;
use App\Domain\Fiscal\Models\RetentionRate;
use App\Domain\Fiscal\Models\TaxRate;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Carga idempotente de los catálogos fiscales versionados.
 *
 * La clave natural incluye `valid_from`: publicar una reforma agrega una fila
 * nueva y conserva la anterior, en lugar de sobrescribir la historia. Repetir
 * la carga con los mismos datos no modifica ninguna fila.
 */
final readonly class SyncFiscalCatalogs
{
    /**
     * @var array<string, list<string>>
     */
    private const REQUIRED_COLUMNS = [
        'tax_rates' => ['code', 'sri_percentage_code', 'rate', 'valid_from'],
        'retention_rates' => ['tax_type', 'code', 'rate', 'valid_from'],
        'form_lines' => ['form_code', 'line_code', 'section', 'label', 'data_type', 'valid_from'],
        'personal_expense_caps' => ['fiscal_year', 'category_code', 'dependents', 'cap_cents', 'valid_from'],
    ];

    public function __construct(private FiscalCatalogData $data) {}

    /**
     * Importa filas ya normalizadas de un catálogo concreto.
     *
     * Comparte el upsert idempotente del catálogo embebido, así que importar
     * dos veces el mismo CSV no duplica filas ni crea una versión nueva.
     *
     * @param  list<array<string, mixed>>  $rows
     * @param  bool  $dryRun  Escribe dentro de una transacción que siempre se revierte
     * @return array{created: int, updated: int, unchanged: int}
     */
    public function import(string $catalog, array $rows, bool $dryRun = false): array
    {
        $target = match ($catalog) {
            'tax_rates' => [TaxRate::class, ['code', 'sri_percentage_code', 'valid_from']],
            'retention_rates' => [RetentionRate::class, ['tax_type', 'code', 'valid_from']],
            'form_lines' => [FormLine::class, ['form_code', 'line_code', 'valid_from']],
            'personal_expense_caps' => [PersonalExpenseCap::class, ['fiscal_year', 'category_code', 'dependents', 'valid_from']],
            default => throw new RuntimeException("Catálogo no soportado: {$catalog}"),
        };

        if ($dryRun) {
            DB::beginTransaction();

            try {
                return $this->sync($target[0], $rows, $target[1], $catalog);
            } finally {
                DB::rollBack();
            }
        }

        return DB::transaction(fn (): array => $this->sync($target[0], $rows, $target[1], $catalog));
    }

    public function handle(): CatalogSyncResult
    {
        return DB::transaction(fn (): CatalogSyncResult => new CatalogSyncResult([
            'tax_rates' => $this->sync(
                TaxRate::class,
                $this->data->taxRates(),
                ['code', 'sri_percentage_code', 'valid_from'],
                'tax_rates',
            ),
            'retention_rates' => $this->sync(
                RetentionRate::class,
                $this->data->retentionRates(),
                ['tax_type', 'code', 'valid_from'],
                'retention_rates',
            ),
            'form_lines' => $this->sync(
                FormLine::class,
                $this->data->formLines(),
                ['form_code', 'line_code', 'valid_from'],
                'form_lines',
            ),
            'personal_expense_caps' => $this->sync(
                PersonalExpenseCap::class,
                $this->data->personalExpenseCaps(),
                ['fiscal_year', 'category_code', 'dependents', 'valid_from'],
                'personal_expense_caps',
            ),
        ]));
    }

    /**
     * @template TModel of Model
     *
     * @param  class-string<TModel>  $modelClass
     * @param  list<array<string, mixed>>  $rows
     * @param  list<string>  $keys
     * @return array{created: int, updated: int, unchanged: int}
     */
    private function sync(string $modelClass, array $rows, array $keys, ?string $catalog = null): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($rows as $index => $row) {
            if ($catalog !== null) {
                $this->assertRowIsImportable($catalog, $index, $row);
            }

            /** @var TModel $model */
            $model = $modelClass::query()->firstOrNew(
                array_intersect_key($row, array_flip($keys)),
            );

            $model->fill($row);

            if (! $model->exists) {
                $model->save();
                $counts['created']++;

                continue;
            }

            if ($model->isDirty()) {
                $model->save();
                $counts['updated']++;

                continue;
            }

            $counts['unchanged']++;
        }

        return $counts;
    }

    /**
     * Una fila importada desde un CSV oficial debe traer las columnas
     * obligatorias del catálogo, una fecha de vigencia y valores dentro de
     * rango. Sin esta comprobación un CSV mal armado entra igual y rompe el
     * cálculo de un período en silencio.
     *
     * @param  array<string, mixed>  $row
     */
    private function assertRowIsImportable(string $catalog, int $index, array $row): void
    {
        foreach (self::REQUIRED_COLUMNS[$catalog] ?? [] as $column) {
            $value = $row[$column] ?? null;

            if ($value === null || $value === '') {
                throw new RuntimeException(sprintf(
                    '%s: la fila %d no trae la columna obligatoria `%s`.',
                    $catalog,
                    $index + 1,
                    $column,
                ));
            }
        }

        if (! isset($row['valid_from']) || ! strtotime((string) $row['valid_from'])) {
            throw new RuntimeException(sprintf('%s: la fila %d no trae una `valid_from` válida.', $catalog, $index + 1));
        }

        if (isset($row['valid_to']) && $row['valid_to'] !== ''
            && strtotime((string) $row['valid_to']) < strtotime((string) $row['valid_from'])) {
            throw new RuntimeException(sprintf('%s: la fila %d termina antes de empezar (`valid_to` < `valid_from`).', $catalog, $index + 1));
        }

        if (isset($row['rate']) && is_numeric($row['rate']) && (float) $row['rate'] < 0.0) {
            throw new RuntimeException(sprintf('%s: la fila %d tiene una tarifa negativa.', $catalog, $index + 1));
        }

        if (isset($row['cap_cents']) && is_numeric($row['cap_cents']) && (int) $row['cap_cents'] < 0) {
            throw new RuntimeException(sprintf('%s: la fila %d tiene un tope negativo.', $catalog, $index + 1));
        }
    }
}
