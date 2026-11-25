<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Actions\ComputePeriodAggregates;
use App\Domain\Fiscal\Models\FiscalPeriod;
use App\Domain\Fiscal\Models\PeriodAggregate;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Throwable;

final class PeriodAggregateController extends Controller
{
    public function __construct(private readonly ComputePeriodAggregates $compute) {}

    public function index(Request $request, Company $company): JsonResponse
    {
        abort_unless(RolePermissions::sameTenant($company), 404);
        Gate::authorize('view', $company);

        [$year, $month] = $this->period($request);

        $period = FiscalPeriod::query()
            ->where('company_id', $company->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        if (! $period instanceof FiscalPeriod) {
            return response()->json(['data' => [], 'meta' => ['period' => null]], 200);
        }

        return response()->json($this->payload($period));
    }

    /**
     * Recalcula los agregados del período (F5-07d).
     *
     * La directiva del sistema es que el recálculo no dependa de un comando
     * manual: corre al cerrar cada sincronización del SRI y en el barrido
     * nocturno. Este endpoint cubre el caso de un período que quedó sin
     * agregados (por un fallo previo) o con datos corregidos fuera de un run.
     */
    public function recompute(Request $request, Company $company): JsonResponse
    {
        abort_unless(RolePermissions::sameTenant($company), 404);
        Gate::authorize('update', $company);

        [$year, $month] = $this->period($request);

        try {
            $period = $this->compute->handle($company, $year, $month);
        } catch (Throwable $exception) {
            return response()->json([
                'data' => null,
                'meta' => [
                    'period' => [
                        'year' => $year,
                        'month' => $month,
                        'aggregation_status' => FiscalPeriod::AGGREGATION_FAILED,
                        'aggregation_error' => $exception->getMessage(),
                    ],
                ],
            ], 500);
        }

        return response()->json($this->payload($period));
    }

    /**
     * @return array{0: int, 1: int}
     */
    private function period(Request $request): array
    {
        $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        return [
            (int) $request->input('year', now()->year),
            (int) $request->input('month', now()->month),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(FiscalPeriod $period): array
    {
        $aggregates = PeriodAggregate::query()
            ->where('fiscal_period_id', $period->id)
            ->orderBy('direction')
            ->orderBy('document_type')
            ->orderBy('tax_code')
            ->get();

        return [
            'data' => $aggregates,
            'meta' => [
                'period' => [
                    'year' => (int) $period->year,
                    'month' => (int) $period->month,
                    'status' => $period->status,
                    'aggregation_status' => $period->aggregation_status,
                    'aggregation_error' => $period->aggregation_error,
                    'aggregation_attempts' => $period->aggregation_attempts,
                    'aggregated_at' => $period->aggregated_at?->toIso8601String(),
                    'computed_at' => $period->aggregates()->max('computed_at'),
                ],
            ],
        ];
    }
}
