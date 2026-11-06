<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Sri\Data\SriChunkImportResult;
use App\Domain\Sri\Data\SriPeriodBatch;
use App\Domain\Sri\Data\SriRunImportReport;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use Illuminate\Support\Facades\Log;

/**
 * Importa los comprobantes de un run en `parsing`.
 *
 * El parser de F2-07 produce los lotes por período y tipo; cada lote se parte
 * en bloques de `sri.sync.chunk_size` claves para que la importación quede
 * acotada. El XML de cada clave se obtiene del S3 del robot o, dentro de la
 * ventana de 45 días, del SRI. El resultado por comprobante se registra en
 * `sri_sync_run_files` y el cierre del run es idempotente.
 *
 * Este comando es la vía síncrona/manual. El pipeline normal usa
 * `ProcessSriRunJob`, que reparte los bloques en `ImportSriChunkJob`.
 */
final readonly class ImportSriRun
{
    public function __construct(
        private ParseSriRun $parse,
        private ImportSriKeys $keys,
        private FinalizeSriRun $finalize,
    ) {}

    /**
     * @return array{run: SriSyncRun, report: SriRunImportReport, sources: array{from_s3: int, from_sri: int}}
     */
    public function handle(SriSyncRun $run): array
    {
        if (! in_array($run->status, [SriSyncRunStatus::Parsing, SriSyncRunStatus::Downloading, SriSyncRunStatus::Invoking], true)) {
            return [
                'run' => $run,
                'report' => $this->emptyReport($run),
                'sources' => $this->emptySources(),
            ];
        }

        $parsed = $this->parse->handle($run);
        $run = $parsed['run'];

        if ($run->status !== SriSyncRunStatus::Parsing) {
            return [
                'run' => $run,
                'report' => $this->emptyReport($run),
                'sources' => $this->emptySources(),
            ];
        }

        $result = $this->importBatches($run, $parsed['batches']);
        $run = $this->finalize->handle($run, $result);

        Log::info('run de importación finalizado', [
            'run_id' => $run->id,
            'found' => $result->found,
            'imported' => $result->imported,
            'updated' => $result->updated,
            'skipped' => $result->skipped,
            'failed' => $result->failed,
            'from_s3' => $result->fromS3,
            'from_sri' => $result->fromSri,
        ]);

        return [
            'run' => $run,
            'report' => new SriRunImportReport(
                found: $result->found,
                imported: $result->imported,
                updated: $result->updated,
                skipped: $result->skipped,
                failed: $result->failed,
                status: $run->status,
            ),
            'sources' => ['from_s3' => $result->fromS3, 'from_sri' => $result->fromSri],
        ];
    }

    /**
     * @param  list<SriPeriodBatch>  $batches
     */
    private function importBatches(SriSyncRun $run, array $batches): SriChunkImportResult
    {
        $chunkSize = max(1, (int) config('sri.sync.chunk_size', 25));
        $result = SriChunkImportResult::empty();

        foreach ($batches as $batch) {
            foreach (array_chunk($batch->values(), $chunkSize) as $keys) {
                $result = $result->merge(
                    $this->keys->handle($run, $batch->documentType, $batch->period, $keys),
                );
            }
        }

        return $result;
    }

    /**
     * @return array{from_s3: int, from_sri: int}
     */
    private function emptySources(): array
    {
        return ['from_s3' => 0, 'from_sri' => 0];
    }

    private function emptyReport(SriSyncRun $run): SriRunImportReport
    {
        return new SriRunImportReport(
            found: 0,
            imported: 0,
            updated: 0,
            skipped: 0,
            failed: 0,
            status: $run->status,
        );
    }
}
