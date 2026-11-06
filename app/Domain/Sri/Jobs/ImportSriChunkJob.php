<?php

declare(strict_types=1);

namespace App\Domain\Sri\Jobs;

use App\Domain\Sri\Actions\ImportSriKeys;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Jobs\Concerns\TenantAware;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Importa un bloque acotado de claves de acceso de un run.
 *
 * El run se parte en lotes de `sri.sync.chunk_size` claves para que ningún
 * job consuma demasiado tiempo ni memoria. Cada comprobante guarda su
 * resultado en `sri_sync_run_files`, así que reintentar el bloque es seguro y
 * el cierre del run no depende de que todos los jobs terminen bien.
 *
 * Los fallos por comprobante los absorbe `ImportSriKeys`; este job solo puede
 * fallar por causas sistémicas (base de datos, cola), que sí se reintentan.
 */
final class ImportSriChunkJob implements ShouldQueue
{
    use Batchable, Queueable, TenantAware;

    public int $tries;

    public int $timeout;

    /** @var list<int> */
    public array $backoff = [30, 120, 300];

    /**
     * @param  list<string>  $keys
     */
    public function __construct(
        public readonly int $runId,
        public readonly string $documentType,
        public readonly string $period,
        public readonly array $keys,
    ) {
        $this->tries = max(1, (int) config('sri.sync.import_tries', 3));
        $this->timeout = max(30, (int) config('sri.sync.import_timeout', 90));
        $this->queue = (string) config('sri.sync.import_queue', 'sri');
    }

    protected function handleInTenant(TenantContext $context): void
    {
        $run = SriSyncRun::query()->find($this->runId);

        if (! $run instanceof SriSyncRun) {
            Log::warning('lote de importación sin run en el tenant activo', [
                'run_id' => $this->runId,
                'tenant_id' => $context->id(),
            ]);

            return;
        }

        if ($run->status !== SriSyncRunStatus::Parsing) {
            Log::info('lote de importación omitido: el run ya no está en parsing', [
                'run_id' => $run->id,
                'status' => $run->status->value,
            ]);

            return;
        }

        $type = SriDocumentType::tryFrom($this->documentType);

        if ($type === null) {
            Log::warning('lote de importación con tipo de comprobante inválido', [
                'run_id' => $run->id,
                'document_type' => $this->documentType,
            ]);

            return;
        }

        $result = app(ImportSriKeys::class)->handle($run, $type, $this->period, $this->keys);

        Log::info('lote de importación procesado', [
            'run_id' => $run->id,
            'period' => $this->period,
            'document_type' => $type->value,
            'found' => $result->found,
            'imported' => $result->imported,
            'updated' => $result->updated,
            'skipped' => $result->skipped,
            'failed' => $result->failed,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        Log::error('un lote de importación agotó los reintentos', [
            'run_id' => $this->runId,
            'period' => $this->period,
            'document_type' => $this->documentType,
            'exception' => $exception?->getMessage(),
        ]);
    }
}
