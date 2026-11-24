<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Enums\FiscalRiskSeverity;
use App\Domain\Fiscal\Enums\FiscalRiskStatus;
use App\Domain\Fiscal\Models\FiscalFinding;
use App\Domain\Fiscal\Models\FiscalPeriod;
use App\Domain\Fiscal\Models\PeriodAggregate;
use App\Domain\Fiscal\Models\PersonalExpenseCap;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Http\Controllers\Controller;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;

/**
 * Panel tributario (F5-01b).
 *
 * Consolida los agregados materializados del período con los hallazgos
 * abiertos para que F4-08 muestre cifras reales sin recalcular en el cliente.
 */
final class DashboardController extends Controller
{
    private const IVA_TAX_CODE = '2';

    public function index(Request $request): JsonResponse
    {
        return $this->build($request, null);
    }

    public function show(Request $request, Company $company): JsonResponse
    {
        return $this->build($request, $company);
    }

    private function build(Request $request, ?Company $company): JsonResponse
    {
        $company ??= $this->resolveCompany($request);

        abort_unless($company instanceof Company, 404);

        abort_unless(RolePermissions::sameTenant($company), 404);
        Gate::authorize('view', $company);

        $year = (int) $request->query('year', (string) now()->year);
        $month = max(1, min(12, (int) $request->query('month', (string) now()->month)));

        $start = Carbon::createFromDate($year, $month, 1)->startOfMonth();
        $end = $start->copy()->endOfMonth();

        $period = FiscalPeriod::query()
            ->where('company_id', $company->id)
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        $directionCounts = $this->directionCounts($company, $start, $end);

        return response()->json([
            'data' => [
                'company' => [
                    'public_id' => $company->public_id,
                    'legal_name' => $company->legal_name,
                    'ruc' => $company->ruc,
                ],
                'period' => [
                    'year' => $year,
                    'month' => $month,
                    'status' => $period?->status,
                    'computed_at' => $period?->aggregates()->max('computed_at'),
                ],
                'iva' => $this->ivaSummary($period, $directionCounts),
                'withholdings' => $this->withholdingSummary($period, $company, $start, $end),
                'top_providers' => $this->topProviders($company, $start, $end),
                'findings' => $this->findingsSummary($company),
                'personal_expense_caps' => $this->personalExpenseCaps($year),
            ],
        ]);
    }

    private function resolveCompany(Request $request): ?Company
    {
        $companyId = $request->query('company_id');

        if (! is_string($companyId) || $companyId === '') {
            return Company::query()->orderBy('id')->first();
        }

        return is_numeric($companyId)
            ? Company::query()->find((int) $companyId)
            : Company::query()->where('public_id', $companyId)->first();
    }

    /**
     * @param  array{sales: int, purchases: int}  $directionCounts
     * @return array{sales: array<string, int>, purchases: array<string, int>, net_cents: int}
     */
    private function ivaSummary(?FiscalPeriod $period, array $directionCounts): array
    {
        $summary = [
            'sales' => ['base_cents' => 0, 'tax_cents' => 0, 'documents_count' => $directionCounts['sales']],
            'purchases' => ['base_cents' => 0, 'tax_cents' => 0, 'documents_count' => $directionCounts['purchases']],
        ];

        if ($period instanceof FiscalPeriod) {
            $rows = PeriodAggregate::query()
                ->where('fiscal_period_id', $period->id)
                ->where('tax_code', self::IVA_TAX_CODE)
                ->get(['direction', 'base_cents', 'tax_cents']);

            foreach ($rows as $row) {
                $bucket = $row->direction === 'sales' ? 'sales' : 'purchases';

                $summary[$bucket]['base_cents'] += (int) $row->base_cents;
                $summary[$bucket]['tax_cents'] += (int) $row->tax_cents;
            }
        }

        return [
            ...$summary,
            'net_cents' => $summary['sales']['tax_cents'] - $summary['purchases']['tax_cents'],
        ];
    }

    /**
     * @return array{received_cents: int, received_documents: int, practiced_cents: int, practiced_documents: int}
     */
    private function withholdingSummary(
        ?FiscalPeriod $period,
        Company $company,
        Carbon $start,
        Carbon $end,
    ): array {
        $received = 0;
        $practiced = 0;

        if ($period instanceof FiscalPeriod) {
            $rows = PeriodAggregate::query()
                ->where('fiscal_period_id', $period->id)
                ->where('document_type', SriDocumentType::Withholding->value)
                ->get(['direction', 'retention_cents']);

            foreach ($rows as $row) {
                if ($row->direction === 'sales') {
                    $received += (int) $row->retention_cents;
                } else {
                    $practiced += (int) $row->retention_cents;
                }
            }
        }

        $base = FiscalDocument::query()
            ->where('company_id', $company->id)
            ->where('document_type', SriDocumentType::Withholding->value)
            ->whereBetween('issue_date', [$start->toDateString(), $end->toDateString()]);

        return [
            'received_cents' => $received,
            'received_documents' => (clone $base)
                ->where('direction', FiscalDocument::DIRECTION_RECEIVED)
                ->count(),
            'practiced_cents' => $practiced,
            'practiced_documents' => (clone $base)
                ->where('direction', FiscalDocument::DIRECTION_ISSUED)
                ->count(),
        ];
    }

    /**
     * @return array<int, array{issuer_ruc: string, issuer_name: string, total_cents: int, documents_count: int}>
     */
    private function topProviders(Company $company, Carbon $start, Carbon $end): array
    {
        return FiscalDocument::query()
            ->where('company_id', $company->id)
            ->where('direction', FiscalDocument::DIRECTION_RECEIVED)
            ->where('document_type', '!=', SriDocumentType::Withholding->value)
            ->whereBetween('issue_date', [$start->toDateString(), $end->toDateString()])
            ->selectRaw('issuer_ruc, issuer_name, SUM(total_cents) AS total_cents, COUNT(*) AS documents_count')
            ->groupBy('issuer_ruc', 'issuer_name')
            ->orderByDesc('total_cents')
            ->limit(5)
            ->get()
            ->map(static fn (FiscalDocument $row): array => [
                'issuer_ruc' => (string) $row->issuer_ruc,
                'issuer_name' => (string) $row->issuer_name,
                'total_cents' => (int) $row->total_cents,
                'documents_count' => (int) $row->getAttribute('documents_count'),
            ])
            ->all();
    }

    /**
     * @return array{open_total: int, by_severity: array<string, int>}
     */
    private function findingsSummary(Company $company): array
    {
        $bySeverity = array_fill_keys(
            array_map(static fn (FiscalRiskSeverity $severity): string => $severity->value, FiscalRiskSeverity::cases()),
            0,
        );

        $rows = FiscalFinding::query()
            ->where('company_id', $company->id)
            ->where('status', FiscalRiskStatus::Open->value)
            ->selectRaw('severity, COUNT(*) AS aggregate')
            ->groupBy('severity')
            ->pluck('aggregate', 'severity');

        foreach ($rows as $severity => $value) {
            $bySeverity[(string) $severity] = (int) $value;
        }

        return [
            'open_total' => array_sum($bySeverity),
            'by_severity' => $bySeverity,
        ];
    }

    /**
     * @return array<int, array{category_code: string, dependents: int, cap_cents: int}>
     */
    private function personalExpenseCaps(int $year): array
    {
        return PersonalExpenseCap::query()
            ->where('fiscal_year', $year)
            ->orderBy('category_code')
            ->orderBy('dependents')
            ->get(['category_code', 'dependents', 'cap_cents'])
            ->map(static fn (PersonalExpenseCap $cap): array => [
                'category_code' => (string) $cap->category_code,
                'dependents' => (int) $cap->dependents,
                'cap_cents' => (int) $cap->cap_cents,
            ])
            ->all();
    }

    /**
     * Cuenta comprobantes por dirección tributaria aplicando la misma regla
     * que `SriDocumentType::tradeDirection()`.
     *
     * @return array{sales: int, purchases: int}
     */
    private function directionCounts(Company $company, Carbon $start, Carbon $end): array
    {
        $base = FiscalDocument::query()
            ->where('company_id', $company->id)
            ->whereBetween('issue_date', [$start->toDateString(), $end->toDateString()]);

        $sales = (clone $base)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('direction', FiscalDocument::DIRECTION_ISSUED)
                        ->whereNotIn('document_type', [
                            SriDocumentType::PurchaseSettlement->value,
                            SriDocumentType::Withholding->value,
                        ]);
                })->orWhere(function (Builder $query): void {
                    $query->where('direction', FiscalDocument::DIRECTION_RECEIVED)
                        ->where('document_type', SriDocumentType::Withholding->value);
                });
            })
            ->count();

        $purchases = (clone $base)
            ->where(function (Builder $query): void {
                $query->where(function (Builder $query): void {
                    $query->where('direction', FiscalDocument::DIRECTION_RECEIVED)
                        ->where('document_type', '!=', SriDocumentType::Withholding->value);
                })->orWhere(function (Builder $query): void {
                    $query->where('direction', FiscalDocument::DIRECTION_ISSUED)
                        ->whereIn('document_type', [
                            SriDocumentType::PurchaseSettlement->value,
                            SriDocumentType::Withholding->value,
                        ]);
                });
            })
            ->count();

        return ['sales' => $sales, 'purchases' => $purchases];
    }
}
