<?php

declare(strict_types=1);

namespace App\Domain\Classification\Actions;

use App\Domain\Classification\Catalogs\ExpenseCategoryData;
use App\Domain\Classification\Models\ExpenseCategory;
use App\Domain\Fiscal\Data\CatalogSyncResult;
use Illuminate\Support\Facades\DB;

/**
 * Carga idempotente del catálogo de categorías de gasto.
 *
 * La clave natural es `code`; repetir la carga con los mismos datos no
 * modifica filas. Los padres se resuelven por código después de insertarlos.
 */
final readonly class SyncExpenseCategories
{
    public function __construct(private ExpenseCategoryData $data) {}

    public function handle(): CatalogSyncResult
    {
        return DB::transaction(function (): CatalogSyncResult {
            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0];

            /** @var array<string, int> $ids */
            $ids = [];

            foreach ($this->data->categories() as $row) {
                $parentCode = $row['parent_code'];
                $attributes = [
                    'name' => $row['name'],
                    'parent_id' => $parentCode === null ? null : ($ids[(string) $parentCode] ?? null),
                    'is_personal_deductible' => (bool) $row['is_personal_deductible'],
                    'is_business_deductible' => (bool) $row['is_business_deductible'],
                    'icon' => $row['icon'],
                    'display_order' => (int) $row['display_order'],
                    'valid_from' => '2000-01-01',
                    'valid_to' => null,
                    'source' => ExpenseCategoryData::SOURCE,
                ];

                $category = ExpenseCategory::query()->firstOrNew(['code' => (string) $row['code']]);
                $category->fill($attributes);

                if (! $category->exists) {
                    $category->save();
                    $counts['created']++;
                } elseif ($category->isDirty()) {
                    $category->save();
                    $counts['updated']++;
                } else {
                    $counts['unchanged']++;
                }

                $ids[(string) $row['code']] = (int) $category->id;
            }

            return new CatalogSyncResult(['expense_categories' => $counts]);
        });
    }
}
