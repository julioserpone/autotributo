<?php

declare(strict_types=1);

namespace App\Domain\Sri\Jobs;

use App\Domain\Sri\Actions\FinalizeSriRun;
use App\Domain\Sri\Actions\ParseSriRun;
use App\Domain\Sri\Data\SriPeriodBatch;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Jobs\Concerns\TenantAware;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Bus\Batch;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Prepara la importación en cola de un run en `parsing`.
 *
 * El job no importa comprobantes: parsea los archivos del robot y reparte las
 * claves en `ImportSriChunkJob` (bloques de `sri.sync.chunk_size`). Cuando el
 * batch termina, `FinalizeSriRunJob` cierra el run con lo persistido.
 *
 * El run se identifica por su id, no por el modelo: el payload no lleva el
 * XML ni credenciales. `TenantAware` garantiza que el worker reactive el
 * tenant antes de consultar; el `TenantScope` impide leer un run ajeno.
 *
 * `ShouldBeUnique` evita que el callback, el scheduler y un reintento manual
 * encolen el mismo run varias veces. El bloqueo se libera al terminar el job,
 * no cuando termina el batch.
 */
final class ProcessSriRunJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries;

    public int $timeout;

    public int $uniqueFor = 3600;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    public function __construct(public readonly int $runId)
    {
        $this->tries = (int) config('sri.sync.import_tries', 3);
        $this->timeout = (int) config('sri.sync.import_timeout', 90);
        $this->queue = (string) config('sri.sync.import_queue', 'sri');
    }

    public function uniqueId(): string
    {
        return (string) $this->runId;
    }

    protected function handleInTenant(TenantContext $context): void
    {
        $run = SriSyncRun::query()->find($this->runId);

        if (! $run instanceof SriSyncRun) {
            Log::warning('run de importación no encontrado en el tenant activo', [
                'run_id' => $this->runId,
                'tenant_id' => $context->id(),
            ]);

            return;
        }

        if ($run->status !== SriSyncRunStatus::Parsing) {
            Log::info('run de importación omitido: ya no está en parsing', [
                'run_id' => $run->id,
                'status' => $run->status->value,
            ]);

            return;
        }

        // El batch puede durar más que este job. Un bloqueo con TTL impide que
        // la reconciliación periódica o un callback tardío encolen un segundo
        // batch mientras el run siga en `parsing`. El finalizador lo libera.
        $lock = Cache::lock($this->lockKey(), 3600);

        if (! $lock->get()) {
            Log::info('importación ya en curso: se omite el duplicado', [
                'run_id' => $run->id,
            ]);

            return;
        }

        try {
            $parsed = app(ParseSriRun::class)->handle($run);
            $run = $parsed['run'];

            if ($run->status !== SriSyncRunStatus::Parsing) {
                $lock->release();

                return;
            }

            $jobs = $this->chunkJobs($run, $parsed['batches']);

            if ($jobs === []) {
                app(FinalizeSriRun::class)->handle($run);
                $lock->release();

                return;
            }

            $this->dispatchBatch($run, $context, $jobs);
        } catch (Throwable $exception) {
            // Si no se llegó a encolar el batch, el reintento debe poder
            // adquirir el bloqueo de nuevo.
            $lock->release();

            throw $exception;
        }
    }

    public function lockKey(): string
    {
        return self::lockKeyFor($this->runId);
    }

    public static function lockKeyFor(int $runId): string
    {
        return 'sri:import:'.$runId;
    }

    /**
     * Libera el bloqueo de importación aunque lo tenga otro proceso.
     *
     * `Cache::forget()` no sirve aquí: con la caché multi-tenant las claves
     * normales llevan prefijo por tenant, pero los locks se guardan con el
     * nombre literal. Por eso se usa `forceRelease()` sobre el mismo nombre.
     */
    public static function releaseLock(int $runId): void
    {
        Cache::lock(self::lockKeyFor($runId), 1)->forceRelease();
    }

    /**
     * @param  list<SriPeriodBatch>  $batches
     * @return list<ImportSriChunkJob>
     */
    private function chunkJobs(SriSyncRun $run, array $batches): array
    {
        $chunkSize = max(1, (int) config('sri.sync.chunk_size', 25));
        $jobs = [];

        foreach ($batches as $batch) {
            foreach (array_chunk($batch->values(), $chunkSize) as $keys) {
                $jobs[] = new ImportSriChunkJob(
                    $run->id,
                    $batch->documentType->value,
                    $batch->period,
                    $keys,
                );
            }
        }

        return $jobs;
    }

    /**
     * @param  list<ImportSriChunkJob>  $jobs
     */
    private function dispatchBatch(SriSyncRun $run, TenantContext $context, array $jobs): void
    {
        $runId = $run->id;
        $tenantId = $context->id();

        Bus::batch($jobs)
            ->allowFailures()
            ->onQueue((string) config('sri.sync.import_queue', 'sri'))
            ->then(static function (Batch $batch) use ($runId, $tenantId): void {
                // `then` corre sin tenant activo: el job finalizador lo
                // reactiva a partir del id antes de encolarse.
                FinalizeSriRunJob::dispatchForTenant($runId, $tenantId);
            })
            ->catch(static function (Batch $batch) use ($runId, $tenantId): void {
                Log::error('el lote de importación falló', [
                    'run_id' => $runId,
                    'batch_id' => $batch->id,
                ]);

                FinalizeSriRunJob::dispatchForTenant($runId, $tenantId);
            })
            ->dispatch();

        Log::info('lote de importación encolado', [
            'run_id' => $runId,
            'jobs' => count($jobs),
        ]);
    }

    /**
     * Se ejecuta cuando se agotan los reintentos. El run no puede quedarse
     * activo para siempre: se cierra como `failed` con un motivo seguro.
     */
    public function failed(?Throwable $exception): void
    {
        Log::error('la importación del run agotó los reintentos', [
            'run_id' => $this->runId,
            'exception' => $exception?->getMessage(),
        ]);

        $run = SriSyncRun::query()->withoutGlobalScopes()->find($this->runId);

        self::releaseLock($this->runId);

        $run?->markFailed(
            'import_failed',
            'No fue posible importar el run tras varios intentos.',
        );
    }
}
