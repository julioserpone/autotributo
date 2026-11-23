<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Actions\DetectFiscalRisks;
use App\Domain\Fiscal\Enums\FiscalRiskSeverity;
use App\Domain\Fiscal\Enums\FiscalRiskStatus;
use App\Domain\Fiscal\Models\FiscalFinding;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Http\Controllers\Controller;
use App\Http\Requests\FiscalFinding\UpdateFiscalFindingRequest;
use App\Http\Resources\FiscalFindingResource;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final class FiscalFindingController extends Controller
{
    public function __construct(
        private readonly DetectFiscalRisks $detectFiscalRisks,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', FiscalFinding::class);

        $filters = (array) $request->input('filter', []);

        $query = FiscalFinding::query()->with(['company', 'resolver']);

        $this->applyFilters($query, $filters, $request);

        // El filtro de estado se aplica antes de los contadores para que las
        // tarjetas resuman el conjunto filtrado (lo que se muestra en la tabla).
        $status = trim((string) ($filters['status'] ?? $request->query('status', '')));

        if ($status !== '' && FiscalRiskStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        // Los contadores reflejan el conjunto filtrado, incluido el estado.
        $counts = $this->counts($query);

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        $findings = $query->latest('id')->paginate($perPage);

        $this->loadSourceDocuments($findings->getCollection());

        return FiscalFindingResource::collection($findings)
            ->additional(['meta' => ['counts' => $counts]]);
    }

    public function update(UpdateFiscalFindingRequest $request, FiscalFinding $fiscal_finding): FiscalFindingResource
    {
        $finding = $fiscal_finding;

        Gate::authorize('update', $finding);

        $validated = $request->validated();

        $finding->status = FiscalRiskStatus::from((string) $validated['status']);

        if (in_array($finding->status, [FiscalRiskStatus::Resolved->value, FiscalRiskStatus::Dismissed->value], true)) {
            $finding->resolved_at = now();
            $finding->resolved_by = $request->user()?->id;
        } else {
            $finding->resolved_at = null;
            $finding->resolved_by = null;
        }

        if (isset($validated['note'])) {
            $metadata = (array) ($finding->metadata ?? []);
            $metadata['note'] = $validated['note'];
            $finding->metadata = $metadata;
        }

        $finding->save();

        return new FiscalFindingResource($finding->load(['company', 'resolver', 'sourceDocument']));
    }

    public function detect(Request $request, Company $company): JsonResponse
    {
        Gate::authorize('detect', FiscalFinding::class);

        $result = $this->detectFiscalRisks->handle($company);

        return response()->json([
            'data' => [
                'created' => $result->created,
                'updated' => $result->updated,
                'resolved' => $result->resolved,
                'total' => $result->total(),
            ],
        ]);
    }

    /**
     * @param  Builder<FiscalFinding>  $query
     * @param  array<string, mixed>  $filters
     */
    private function applyFilters(Builder $query, array $filters, Request $request): void
    {
        $companyId = $filters['company_id'] ?? $request->query('company_id');

        if ($companyId !== null && $companyId !== '') {
            $company = is_numeric($companyId)
                ? Company::query()->find((int) $companyId)
                : Company::query()->where('public_id', (string) $companyId)->first();

            if ($company !== null) {
                $query->where('company_id', $company->id);
            }
        }

        $code = trim((string) ($filters['code'] ?? $request->query('code', '')));

        if ($code !== '') {
            $query->where('code', $code);
        }

        $severity = trim((string) ($filters['severity'] ?? $request->query('severity', '')));

        if ($severity !== '') {
            $query->where('severity', $severity);
        }

        $search = trim((string) ($filters['search'] ?? $request->query('search', '')));

        if ($search !== '') {
            $query->where(function (Builder $builder) use ($search): void {
                $builder->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        }
    }

    /**
     * @param  Builder<FiscalFinding>  $query
     * @return array{total: int, by_status: array<string, int>, by_severity: array<string, int>}
     */
    private function counts(Builder $query): array
    {
        $base = (clone $query)->toBase();

        $byStatus = array_fill_keys(
            array_map(static fn (FiscalRiskStatus $status): string => $status->value, FiscalRiskStatus::cases()),
            0,
        );

        $bySeverity = array_fill_keys(
            array_map(static fn (FiscalRiskSeverity $severity): string => $severity->value, FiscalRiskSeverity::cases()),
            0,
        );

        /** @var Collection<string, int|string> $statusRows */
        $statusRows = (clone $base)
            ->selectRaw('status, count(*) as aggregate')
            ->groupBy('status')
            ->pluck('aggregate', 'status');

        /** @var Collection<string, int|string> $severityRows */
        $severityRows = (clone $base)
            ->selectRaw('severity, count(*) as aggregate')
            ->groupBy('severity')
            ->pluck('aggregate', 'severity');

        foreach ($statusRows as $status => $value) {
            $byStatus[(string) $status] = (int) $value;
        }

        foreach ($severityRows as $severity => $value) {
            $bySeverity[(string) $severity] = (int) $value;
        }

        return [
            'total' => array_sum($byStatus),
            'by_status' => $byStatus,
            'by_severity' => $bySeverity,
        ];
    }

    /**
     * Carga en lote el comprobante de origen de la página actual.
     *
     * @param  Collection<int, FiscalFinding>  $findings
     */
    private function loadSourceDocuments(Collection $findings): void
    {
        $ids = $findings
            ->where('source_type', 'fiscal_document')
            ->pluck('source_id')
            ->unique()
            ->all();

        if ($ids === []) {
            return;
        }

        $documents = FiscalDocument::query()->whereIn('id', $ids)->get()->keyBy('id');

        foreach ($findings as $finding) {
            $finding->setRelation('sourceDocument', $documents->get($finding->source_id));
        }
    }
}
