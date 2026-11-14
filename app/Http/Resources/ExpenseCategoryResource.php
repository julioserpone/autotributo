<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Classification\Models\ExpenseCategory;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Categoría de gasto del catálogo global.
 *
 * @property-read ExpenseCategory $resource
 */
final class ExpenseCategoryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $category = $this->resource;

        return [
            'id' => $category->id,
            'code' => $category->code,
            'name' => $category->name,
            'parent_id' => $category->parent_id,
            'is_personal_deductible' => $category->is_personal_deductible,
            'is_business_deductible' => $category->is_business_deductible,
            'icon' => $category->icon,
            'display_order' => $category->display_order,
        ];
    }
}
