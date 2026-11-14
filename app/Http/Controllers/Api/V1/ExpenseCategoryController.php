<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classification\Models\ExpenseCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\ExpenseCategoryResource;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Catálogo global de categorías de gasto para reglas, filtros y revisión.
 */
final class ExpenseCategoryController extends Controller
{
    public function index(): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', ExpenseCategory::class);

        return ExpenseCategoryResource::collection(
            ExpenseCategory::query()
                ->currentlyValid()
                ->orderBy('display_order')
                ->orderBy('name')
                ->get(),
        );
    }
}
