<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Classification\Actions\BulkReviewClassifications;
use App\Domain\Classification\Actions\ReviewClassification;
use App\Domain\Classification\Data\ClassificationReviewData;
use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Models\Classification;
use App\Http\Controllers\Controller;
use App\Http\Requests\Classification\BulkReviewClassificationsRequest;
use App\Http\Requests\Classification\ReviewClassificationRequest;
use App\Http\Resources\ClassificationResource;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Gate;

/**
 * Revisión humana de clasificaciones (F3-05 / US-18).
 *
 * La cola prioriza lo que necesita una persona (`needs_review`) sobre las
 * sugerencias automáticas, y el listado se filtra por estado, método, empresa
 * y texto. Decidir exige el permiso `fiscal_documents.review`; consultar, `fiscal_documents.view`.
 */
final class ClassificationController extends Controller
{
    public function __construct(
        private readonly ReviewClassification $reviewClassification,
        private readonly BulkReviewClassifications $bulkReview,
    ) {}

    /**
     * Cola de revisión paginada.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        Gate::authorize('viewAny', Classification::class);

        $query = Classification::query()
            ->with(['fiscal_documentItem.fiscal_document', 'expenseCategory', 'reviewer']);

        $filters = (array) $request->input('filter', []);

        $status = trim((string) ($filters['status'] ?? $request->query('status', '')));

        if ($status !== '' && ClassificationStatus::tryFrom($status) !== null) {
            $query->where('status', $status);
        }

        $method = trim((string) ($filters['method'] ?? $request->query('method', '')));

        if ($method !== '' && ClassificationMethod::tryFrom($method) !== null) {
            $query->where('method', $method);
        }

        $companyId = $filters['company_id'] ?? $request->query('company_id');

        if ($companyId !== null && $companyId !== '') {
            $query->whereHas(
                'fiscal_documentItem.fiscal_document',
                fn (Builder $builder) => $builder->where('company_id', (int) $companyId),
            );
        }

        $reviewed = $filters['reviewed'] ?? $request->query('reviewed');

        if ($reviewed !== null && $reviewed !== '') {
            if (filter_var($reviewed, FILTER_VALIDATE_BOOLEAN)) {
                $query->whereNotNull('reviewed_at');
            } else {
                $query->whereNull('reviewed_at');
            }
        }

        $search = trim((string) ($filters['search'] ?? $request->query('search', '')));

        if ($search !== '') {
            $query->whereHas(
                'fiscal_documentItem',
                fn (Builder $builder) => $builder->where('description', 'like', "%{$search}%"),
            );
        }

        $perPage = max(1, min((int) $request->integer('per_page', 25), 100));

        return ClassificationResource::collection(
            $query
                // Lo que espera a una persona va primero; el resto, por antigüedad.
                ->orderByRaw('case when status = ? then 0 when status = ? then 1 else 2 end', [
                    ClassificationStatus::NeedsReview->value,
                    ClassificationStatus::Suggested->value,
                ])
                ->orderBy('id')
                ->paginate($perPage),
        );
    }

    /**
     * Acepta la categoría sugerida, la corrige o rechaza la clasificación.
     */
    public function review(
        ReviewClassificationRequest $request,
        Classification $classification,
    ): ClassificationResource {
        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $updated = $this->reviewClassification->handle(
            $classification,
            ClassificationReviewData::fromArray($request->validated()),
            $actor,
        );

        return new ClassificationResource(
            $updated->load(['fiscal_documentItem.fiscal_document', 'expenseCategory', 'reviewer']),
        );
    }

    /**
     * Aplica la misma decisión a un lote de clasificaciones.
     */
    public function bulkReview(BulkReviewClassificationsRequest $request): JsonResponse
    {
        $actor = $request->user();

        if (! $actor instanceof User) {
            abort(401);
        }

        $validated = $request->validated();

        $result = $this->bulkReview->handle(
            array_values(array_map(static fn (mixed $id): int => (int) $id, (array) $validated['ids'])),
            ClassificationReviewData::fromArray($validated),
            $actor,
        );

        return response()->json(['data' => $result->toArray()]);
    }
}
