<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Fiscal\Actions\RecordFiscalSignoffReview;
use App\Domain\Fiscal\Catalogs\FiscalSignoffStageCatalog;
use App\Domain\Fiscal\Enums\FiscalSignoffDecision;
use App\Domain\Fiscal\Exceptions\FiscalSignoffStageChanged;
use App\Domain\Fiscal\Models\FiscalSignoffReview;
use App\Domain\Tenancy\TenantContext;
use App\Http\Controllers\Controller;
use App\Http\Requests\Fiscal\StoreFiscalSignoffReviewRequest;
use App\Models\User;
use App\Support\Http\ProblemDetails;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Gate;

final class FiscalSignoffController extends Controller
{
    public function index(): JsonResponse
    {
        Gate::authorize('viewAny', FiscalSignoffReview::class);

        $stageKeys = array_column(FiscalSignoffStageCatalog::all(), 'key');

        /** @var Collection<int, FiscalSignoffReview> $reviews */
        $reviews = FiscalSignoffReview::query()
            ->with('reviewer')
            ->whereIn('stage_key', $stageKeys)
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->get();

        $stages = array_map(function (array $stage) use ($reviews): array {
            $fingerprint = FiscalSignoffStageCatalog::fingerprint($stage);
            $stageReviews = $reviews->where('stage_key', $stage['key'])->values();
            $currentReview = $stageReviews->first(
                fn (FiscalSignoffReview $review): bool => $review->stage_version === $stage['version']
                    && $review->stage_fingerprint === $fingerprint,
            );

            return [
                ...$stage,
                'fingerprint' => $fingerprint,
                'status' => $currentReview instanceof FiscalSignoffReview
                    ? $currentReview->decision->value
                    : 'pending',
                'latest_review' => $currentReview instanceof FiscalSignoffReview
                    ? $this->reviewPayload($currentReview)
                    : null,
                'history' => $stageReviews->map(fn (FiscalSignoffReview $review): array => [
                    ...$this->reviewPayload($review),
                    'current_version' => $review->stage_version === $stage['version']
                        && $review->stage_fingerprint === $fingerprint,
                ])->all(),
            ];
        }, FiscalSignoffStageCatalog::all());

        return response()->json(['data' => $stages]);
    }

    public function store(
        StoreFiscalSignoffReviewRequest $request,
        string $stage,
        RecordFiscalSignoffReview $recordReview,
        TenantContext $tenantContext,
    ): JsonResponse {
        $reviewer = $request->user();

        abort_unless($reviewer instanceof User, 401);

        $validated = $request->validated();

        try {
            $review = $recordReview->handle(
                reviewer: $reviewer,
                stageKey: $stage,
                stageVersion: (int) $validated['stage_version'],
                stageFingerprint: (string) $validated['stage_fingerprint'],
                decision: FiscalSignoffDecision::from((string) $validated['decision']),
                comments: is_string($validated['comments'] ?? null) ? $validated['comments'] : null,
                tenantContext: $tenantContext,
            );
        } catch (FiscalSignoffStageChanged $exception) {
            return ProblemDetails::conflict('fiscal-signoff-stage-changed', $exception->getMessage());
        }

        $review->load('reviewer');

        return response()->json([
            'data' => $this->reviewPayload($review),
        ], 201);
    }

    /**
     * @return array<string, mixed>
     */
    private function reviewPayload(FiscalSignoffReview $review): array
    {
        return [
            'stage_key' => $review->stage_key,
            'stage_version' => $review->stage_version,
            'stage_fingerprint' => $review->stage_fingerprint,
            'decision' => $review->decision->value,
            'comments' => $review->comments,
            'reviewed_by' => $review->reviewer === null
                ? null
                : ['name' => $review->reviewer->name],
            'reviewed_at' => $review->reviewed_at->toIso8601String(),
        ];
    }
}
