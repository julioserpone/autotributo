<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Actions\DetectFiscalRisks;
use App\Domain\Fiscal\Enums\FiscalRiskStatus;
use App\Domain\Fiscal\Models\FiscalFinding;
use App\Http\Controllers\Controller;
use App\Http\Requests\FiscalFinding\UpdateFiscalFindingRequest;
use App\Http\Resources\FiscalFindingResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

final class FiscalFindingController extends Controller
{
    public function __construct(
        private readonly DetectFiscalRisks $detectFiscalRisks,
    ) {}

    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', FiscalFinding::class);

        $query = FiscalFinding::query()->with('resolver');

        $filters = (array) $request->input('filter', []);

        $companyId = $filters['company_id'] ?? $request->query('company_id');

        if ($companyId !== null && $companyId !== '') {
            $query->where('company_id', (int) $companyId);
        }

        $code = trim((string) ($filters['code'] ?? $request->query('code', '')));

        if ($code !== '') {
            $query->where('code', $code);
        }

        $status = trim((string) ($filters['status'] ?? $request->query('status', '')));

        if ($status !== '' && FiscalRiskStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $severity = trim((string) ($filters['severity'] ?? $request->query('severity', '')));

        if ($severity !== '') {
            $query->where('severity', $severity);
        }

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        return FiscalFindingResource::collection($query->latest('id')->paginate($perPage));
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

        return new FiscalFindingResource($finding->load('resolver'));
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
}
