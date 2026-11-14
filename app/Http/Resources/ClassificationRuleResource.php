<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Classification\Models\ClassificationRule;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Regla de clasificación expuesta a la UI de configuración.
 *
 * @property-read ClassificationRule $resource
 */
final class ClassificationRuleResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $rule = $this->resource;

        return [
            'id' => $rule->id,
            'name' => $rule->name,
            'priority' => $rule->priority,
            'match_type' => $rule->match_type->value,
            'match_type_label' => $rule->match_type->label(),
            'pattern' => $rule->pattern,
            'is_global' => $rule->isGlobal(),
            'is_active' => $rule->is_active,
            'expense_category' => $rule->relationLoaded('expenseCategory') && $rule->expenseCategory !== null
                ? new ExpenseCategoryResource($rule->expenseCategory)
                : null,
            'hits_count' => $rule->hits_count,
            'last_matched_at' => $rule->last_matched_at?->toIso8601String(),
            'created_at' => $rule->created_at?->toIso8601String(),
            'updated_at' => $rule->updated_at?->toIso8601String(),
        ];
    }
}
