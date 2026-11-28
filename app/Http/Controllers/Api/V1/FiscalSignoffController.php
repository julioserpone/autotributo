<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Fiscal\Actions\RecordFiscalSignoffReview;
use App\Domain\Fiscal\Catalogs\FiscalSignoffSourceCatalog;
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

    public function sourceFiles(string $stage): JsonResponse
    {
        Gate::authorize('viewSource', FiscalSignoffReview::class);

        $stageData = FiscalSignoffStageCatalog::find($stage);

        if ($stageData === null) {
            return ProblemDetails::notFound('La etapa de validación no existe.');
        }

        $files = array_map(function (array $file): array {
            $path = $this->safePath($file['path']);

            return [
                'id' => $file['id'],
                'label' => $file['label'],
                'language' => $file['language'],
                'description' => $file['description'],
                'line_count' => $path === null ? 0 : $this->lineCount($path),
                'size_bytes' => $path === null ? 0 : (filesize($path) ?: 0),
            ];
        }, FiscalSignoffSourceCatalog::forStage($stage));

        return response()->json(['data' => $files]);
    }

    public function sourceFile(string $stage, string $sourceFile): JsonResponse
    {
        Gate::authorize('viewSource', FiscalSignoffReview::class);

        $stageData = FiscalSignoffStageCatalog::find($stage);

        if ($stageData === null) {
            return ProblemDetails::notFound('La etapa de validación no existe.');
        }

        $file = FiscalSignoffSourceCatalog::find($stage, $sourceFile);

        if ($file === null) {
            return ProblemDetails::notFound('El archivo fuente solicitado no existe para esta etapa.');
        }

        $path = $this->safePath($file['path']);

        if ($path === null) {
            return ProblemDetails::notFound('El archivo fuente solicitado no existe.');
        }

        $size = filesize($path) ?: 0;

        if ($size > self::MAX_SOURCE_FILE_BYTES) {
            return ProblemDetails::make(413, 'source-file-too-large', 'Archivo demasiado grande', 'El archivo fuente supera el tamaño máximo permitido para su visualización.');
        }

        $content = file_get_contents($path);

        if ($content === false) {
            return ProblemDetails::serverError('No se pudo leer el archivo fuente.');
        }

        return response()->json([
            'data' => [
                'id' => $file['id'],
                'label' => $file['label'],
                'language' => $file['language'],
                'description' => $file['description'],
                'content' => $content,
                'line_count' => substr_count($content, "\n") + ($content === '' ? 0 : 1),
                'size_bytes' => $size,
                'sha256' => hash_file('sha256', $path) ?: '',
            ],
        ]);
    }

    private const MAX_SOURCE_FILE_BYTES = 200000;

    private function safePath(string $relativePath): ?string
    {
        $base = realpath(base_path());

        if ($base === false) {
            return null;
        }

        $fullPath = base_path($relativePath);
        $realPath = realpath($fullPath);

        if ($realPath === false || ! str_starts_with($realPath, $base.DIRECTORY_SEPARATOR)) {
            return null;
        }

        $extension = strtolower(pathinfo($realPath, PATHINFO_EXTENSION));

        if (! in_array($extension, ['php', 'md', 'ts', 'vue', 'js', 'json'], true)) {
            return null;
        }

        return $realPath;
    }

    private function lineCount(string $path): int
    {
        $content = file_get_contents($path);

        if ($content === false || $content === '') {
            return 0;
        }

        return substr_count($content, "\n") + 1;
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
