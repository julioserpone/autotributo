<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Actions\BuildForm104Draft;
use App\Domain\Fiscal\Data\Form104Draft;
use App\Domain\Fiscal\Models\FormLine;
use App\Domain\Tenancy\Permissions\RolePermissions;
use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

/**
 * Borrador del Formulario 104 (F4-09).
 *
 * Solo lectura: el borrador se deriva de `period_aggregates` y del catálogo de
 * casilleros vigente. La declaración persistida, su ciclo de vida y las
 * exportaciones son de F5-02/F5-05.
 */
final class Form104Controller extends Controller
{
    public function __construct(private readonly BuildForm104Draft $build) {}

    public function index(Request $request): JsonResponse
    {
        $company = $this->resolveCompany($request);

        abort_unless($company instanceof Company, 404);

        return $this->buildFor($request, $company);
    }

    public function show(Request $request, Company $company): JsonResponse
    {
        return $this->buildFor($request, $company);
    }

    private function buildFor(Request $request, Company $company): JsonResponse
    {
        abort_unless(RolePermissions::sameTenant($company), 404);

        Gate::authorize('viewAny', FormLine::class);

        $request->validate([
            'year' => ['nullable', 'integer', 'between:2000,2100'],
            'month' => ['nullable', 'integer', 'between:1,12'],
        ]);

        $year = (int) $request->query('year', (string) now()->year);
        $month = (int) $request->query('month', (string) now()->month);

        $draft = $this->build->handle($company, $year, $month);

        return response()->json($this->payload($draft));
    }

    /**
     * @return array{data: array<string, mixed>}
     */
    private function payload(Form104Draft $draft): array
    {
        return [
            'data' => [
                'company' => $draft->company,
                'period' => $draft->period,
                'status' => $draft->status,
                'lines' => $draft->lines,
                'proportionality' => $draft->proportionality,
                'previous_periods' => $draft->previous_periods,
                'findings' => $draft->findings,
                'official_form' => $draft->official_form,
            ],
        ];
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
}
