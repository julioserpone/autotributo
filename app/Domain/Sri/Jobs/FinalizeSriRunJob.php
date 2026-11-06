<?php

declare(strict_types=1);

namespace App\Domain\Sri\Jobs;

use App\Domain\Sri\Actions\FinalizeSriRun;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Jobs\Concerns\TenantAware;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cierra un run cuando terminó el lote de importación.
 *
 * El veredicto se calcula desde `sri_sync_run_files`, no desde la memoria del
 * proceso: si un job se perdió, el run igual se cierra con lo que quedó
 * persistido. Es idempotente, de modo que un `then` y un `catch` simultáneos
 * no cambian el resultado.
 *
 * El callback `then` de un batch no corre con tenant activo. Por eso
 * `dispatchForTenant()` reactiva el tenant solo para encolar el job y que el
 * `QueueTenancyBootstrapper` transporte el `tenant_id` en el payload.
 */
final class FinalizeSriRunJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries;

    public int $timeout;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $runId)
    {
        $this->tries = max(1, (int) config('sri.sync.import_tries', 3));
        $this->timeout = max(30, (int) config('sri.sync.import_timeout', 90));
        $this->queue = (string) config('sri.sync.import_queue', 'sri');
    }

    public function uniqueId(): string
    {
        return 'finalize-'.$this->runId;
    }

    /**
     * Encola el cierre con el tenant del run.
     *
     * Se usa desde los callbacks `then`/`catch` del batch, que se ejecutan
     * fuera del contexto de tenant.
     */
    public static function dispatchForTenant(int $runId, int $tenantId): void
    {
        $tenant = Tenant::query()->find($tenantId);

        if (! $tenant instanceof Tenant) {
            Log::warning('no se pudo encolar el cierre del run: el tenant ya no existe', [
                'run_id' => $runId,
                'tenant_id' => $tenantId,
            ]);

            return;
        }

        app(TenantContext::class)->run($tenant, static fn () => self::dispatch($runId));
    }

    protected function handleInTenant(TenantContext $context): void
    {
        $run = SriSyncRun::query()->find($this->runId);

        if (! $run instanceof SriSyncRun) {
            Log::warning('cierre de run sin run en el tenant activo', [
                'run_id' => $this->runId,
                'tenant_id' => $context->id(),
            ]);

            Cache::forget($this->lockKey());

            return;
        }

        try {
            app(FinalizeSriRun::class)->handle($run);
        } finally {
            // El batch ya terminó: se libera el bloqueo que tomó
            // `ProcessSriRunJob` para evitar batches duplicados.
            Cache::forget($this->lockKey());
        }
    }

    public function lockKey(): string
    {
        return 'sri:import:'.$this->runId;
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('el cierre del run agotó los reintentos', [
            'run_id' => $this->runId,
            'exception' => $exception?->getMessage(),
        ]);

        $run = SriSyncRun::query()->withoutGlobalScopes()->find($this->runId);

        Cache::forget($this->lockKey());

        if ($run instanceof SriSyncRun && $run->status === SriSyncRunStatus::Parsing) {
            $run->markFailed(
                'import_failed',
                'No fue posible cerrar la importación del run tras varios intentos.',
            );
        }
    }
}
