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

/**
 * Carga idempotente de los catálogos fiscales versionados.
 *
 * La clave natural incluye `valid_from`: publicar una reforma agrega una fila
 * nueva y conserva la anterior, en lugar de sobrescribir la historia. Repetir
 * la carga con los mismos datos no modifica ninguna fila.
 */
final readonly class SyncFiscalCatalogs
{
    public function __construct(private FiscalCatalogData $data) {}

    public function handle(): CatalogSyncResult
    {
        return DB::transaction(fn (): CatalogSyncResult => new CatalogSyncResult([
            'tax_rates' => $this->sync(
                TaxRate::class,
                $this->data->taxRates(),
                ['code', 'sri_percentage_code', 'valid_from'],
            ),
            'retention_rates' => $this->sync(
                RetentionRate::class,
                $this->data->retentionRates(),
                ['tax_type', 'code', 'valid_from'],
            ),
            'form_lines' => $this->sync(
                FormLine::class,
                $this->data->formLines(),
                ['form_code', 'line_code', 'valid_from'],
            ),
            'personal_expense_caps' => $this->sync(
                PersonalExpenseCap::class,
                $this->data->personalExpenseCaps(),
                ['fiscal_year', 'category_code', 'dependents', 'valid_from'],
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
    private function sync(string $modelClass, array $rows, array $keys): array
    {
        $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

        foreach ($rows as $row) {
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
}
