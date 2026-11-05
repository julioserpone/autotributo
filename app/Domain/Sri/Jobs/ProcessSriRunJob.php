<?php

declare(strict_types=1);

namespace App\Domain\Sri\Jobs;

use App\Domain\Sri\Actions\ImportSriRun;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Jobs\Concerns\TenantAware;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Importa en cola los comprobantes de un run en `parsing`.
 *
 * El run se identifica por su id, no por el modelo: el payload no lleva el
 * XML ni credenciales. `TenantAware` garantiza que el worker reactiva el
 * tenant antes de consultar; el `TenantScope` impide leer un run ajeno.
 *
 * `ShouldBeUnique` evita que el callback, el scheduler y un reintento manual
 * encolen el mismo run varias veces. El bloqueo se libera al terminar el job,
 * no al encolarse.
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

        app(ImportSriRun::class)->handle($run);
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

        $run?->markFailed(
            'import_failed',
            'No fue posible importar el run tras varios intentos.',
        );
    }
}
