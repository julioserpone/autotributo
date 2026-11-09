<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Actions\BuildSriRobotRequest;
use App\Domain\Sri\Actions\StartSriSync;
use App\Domain\Sri\Models\SriSyncRun;
use App\Http\Controllers\Controller;
use App\Http\Requests\Sri\StoreSyncRunRequest;
use App\Http\Resources\SriSyncRunResource;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

/**
 * Dispara una sincronización del robot con las credenciales persistidas.
 *
 * La respuesta es `202` con los runs creados: el trabajo real ocurre en la
 * Lambda y en la cola `sri`. El estado se consulta por polling (F2-09a) en
 * `GET /sync-runs/{run}`.
 */
final class SyncRunController extends Controller
{
    public function __construct(
        private readonly BuildSriRobotRequest $buildRequest,
        private readonly StartSriSync $startSync,
    ) {}

    public function store(StoreSyncRunRequest $request): JsonResponse
    {
        $company = Company::query()
            ->where('public_id', (string) $request->string('company_id'))
            ->firstOrFail();

        $robotRequest = $this->buildRequest->handle(
            $company,
            array_values($request->input('periods', [])),
            $request->input('document_types'),
            (bool) $request->boolean('force'),
            $request->user()?->getKey(),
            $request->ip(),
        );

        $batch = $this->startSync->handle(
            $company,
            $robotRequest,
            $request->idempotencyKey(),
            $request->user()?->getKey(),
        );

        $location = route('api.v1.sync-runs.show', ['run' => $batch->first()->public_id]);

        return response()->json([
            'data' => array_map(
                static fn (SriSyncRun $run): array => [
                    'id' => $run->public_id,
                    'status' => $run->status->value,
                ],
                $batch->runs,
            ),
            'meta' => ['count' => $batch->count()],
            'status_url' => $location,
        ], 202)->header('Location', $location);
    }

    /**
     * Estado del run para el polling del monitor (F2-09a).
     */
    public function show(SriSyncRun $run): SriSyncRunResource
    {
        Gate::authorize('view', $run);

        return new SriSyncRunResource($run->load('company'));
    }
}
