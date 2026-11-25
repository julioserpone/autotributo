<?php

declare(strict_types=1);

namespace App\Domain\Classification\Actions;

use App\Domain\Classification\Catalogs\ClassificationRuleData;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Classification\Models\ExpenseCategory;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\DB;

/**
 * Carga idempotente de las reglas base para emisores recurrentes.
 *
 * Las reglas nacen **inactivas** y sin tenant: el estudio decide cuáles
 * activar. La clave natural es (`tenant_id` nulo, `match_type`, `pattern`,
 * `expense_category_id`), así que reejecutar no duplica. Si una regla base ya
 * existía, se respeta su `is_active` y su `priority`: activarla o desactivarla
 * es una decisión del usuario y no debe revertirse en cada `db:seed`.
 */
final readonly class SyncClassificationRules
{
    public function __construct(private ClassificationRuleData $data) {}

    /**
     * @return array{created: int, updated: int, unchanged: int, skipped: int}
     */
    public function handle(): array
    {
        return DB::transaction(function (): array {
            $counts = ['created' => 0, 'updated' => 0, 'unchanged' => 0, 'skipped' => 0];

            $categoryIds = ExpenseCategory::query()
                ->pluck('id', 'code')
                ->all();

            foreach ($this->data->rules() as $row) {
                $categoryId = $categoryIds[$row['category_code']] ?? null;

                if ($categoryId === null) {
                    // Sin categoría no hay regla: se informa y se sigue.
                    $counts['skipped']++;

                    continue;
                }

                $rule = ClassificationRule::query()
                    ->withoutGlobalScopes()
                    ->withTrashed()
                    ->whereNull('tenant_id')
                    ->where('match_type', $row['match_type']->value)
                    ->where('pattern', $row['pattern'])
                    ->where('expense_category_id', $categoryId)
                    ->first();

                if ($rule instanceof ClassificationRule) {
                    if ($rule->trashed()) {
                        // Estaba dado de baja: al recuperarlo sigue contando como
                        // existente, no como una regla nueva.
                        $rule->restore();
                        $rule->fill(['name' => $row['name']]);
                        $rule->save();
                        $counts['unchanged']++;

                        continue;
                    }

                    $rule->fill(['name' => $row['name']]);

                    if ($rule->isDirty()) {
                        $rule->save();
                        $counts['updated']++;
                    } else {
                        $counts['unchanged']++;
                    }

                    continue;
                }

                // `BelongsToTenantOrGlobal` asigna el tenant activo a cualquier
                // fila nueva sin `tenant_id`; aquí la regla debe ser global, así
                // que se crea sin contexto de tenant.
                $context = app(TenantContext::class);
                $tenantId = $context->isInitialized() ? $context->id() : null;

                if ($tenantId !== null) {
                    $context->end();
                }

                try {
                    ClassificationRule::query()->create([
                        'tenant_id' => null,
                        'name' => $row['name'],
                        'priority' => $row['priority'],
                        'match_type' => $row['match_type'],
                        'pattern' => $row['pattern'],
                        'expense_category_id' => $categoryId,
                        'is_active' => false,
                        'created_by' => null,
                    ]);
                } finally {
                    if ($tenantId !== null) {
                        $context->initialize(Tenant::query()->findOrFail($tenantId));
                    }
                }

                $counts['created']++;
            }

            return $counts;
        });
    }
}
