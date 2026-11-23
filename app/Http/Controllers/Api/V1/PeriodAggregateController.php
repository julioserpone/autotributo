<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Models\FiscalPeriod;
use App\Domain\Fiscal\Models\PeriodAggregate;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

final class PeriodAggregateController extends Controller
{
    public function index(Request $request, Company $company): JsonResponse
    {
        abort_unless(RolePermissions::sameTenant($company), 404);
        Gate::authorize('view', $company);

        $year = (int) $request->query('year', now()->year);
        $month = (int) $request->query('month', now()->month);

        $period = FiscalPeriod::query()
            ->where('company_id', $company->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if (! $period instanceof FiscalPeriod) {
            return response()->json(['data' => [], 'meta' => ['period' => null]], 200);
        }

        $aggregates = PeriodAggregate::query()
            ->where('fiscal_period_id', $period->id)
            ->orderBy('direction')
            ->orderBy('document_type')
            ->orderBy('tax_code')
            ->get();

        return response()->json([
            'data' => $aggregates,
            'meta' => [
                'period' => [
                    'year' => $period->year,
                    'month' => $period->month,
                    'status' => $period->status,
                    'computed_at' => $period->aggregates()->max('computed_at'),
                ],
            ],
        ]);
    }
}
