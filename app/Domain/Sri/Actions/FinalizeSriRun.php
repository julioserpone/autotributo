<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Sri\Data\SriChunkImportResult;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;

/**
 * Cierra un run de sincronización basándose en el resultado acumulado o en
 * los registros de comprobantes en `sri_sync_run_files`.
 */
final readonly class FinalizeSriRun
{
    public function handle(
        SriSyncRun $run,
        ?SriChunkImportResult $result = null,
        string $failureCode = 'import_failed',
        string $failureMessage = 'No fue posible importar ningún comprobante del run.',
    ): SriSyncRun {
        if ($run->status->isFinal()) {
            return $run;
        }

        if ($result === null) {
            $result = $this->aggregateFromFiles($run);
        }

        // `ParseSriRun` dejó en `totals.found` cuántas claves debía importar el
        // run. Si el batch perdió un bloque, esas claves no tienen fila: el run
        // no puede cerrarse como completo solo porque no hay fallos guardados.
        $expected = (int) ($run->totals['found'] ?? 0);
        $missing = max(0, $expected - $result->found);
        $failed = $result->failed + $missing;

        $totals = [
            'found' => $result->found,
            'imported' => $result->imported,
            'updated' => $result->updated,
            'skipped' => $result->skipped,
            'failed' => $failed,
            'from_s3' => $result->fromS3,
            'from_sri' => $result->fromSri,
        ];

        $status = match (true) {
            $failed === 0 => SriSyncRunStatus::Completed,
            $result->stored() > 0 => SriSyncRunStatus::Partial,
            default => SriSyncRunStatus::Failed,
        };

        $run->totals = $totals;

        if ($status === SriSyncRunStatus::Failed) {
            $run->error_code = $failureCode;
            $run->error_message = $failureMessage;
        } elseif ($status === SriSyncRunStatus::Partial) {
            $run->error_code = 'partial_import';
            $run->error_message = sprintf(
                '%d comprobante(s) no se pudieron importar.',
                $failed,
            );
        } else {
            $run->error_code = null;
            $run->error_message = null;
        }

        $run->transitionTo($status);

        return $run->refresh();
    }

    private function aggregateFromFiles(SriSyncRun $run): SriChunkImportResult
    {
        $rows = $run->files()
            ->where('s3_key', 'like', '%-xml/%')
            ->get();

        $found = $rows->count();
        $imported = $rows->where('import_outcome', 'created')->count();
        $updated = $rows->where('import_outcome', 'updated')->count();
        $skipped = $rows->where('import_outcome', 'skipped')->count();
        $failed = $rows->whereNotNull('error_code')->count();
        $fromS3 = $rows->where('source', 's3')->count();
        $fromSri = $rows->where('source', 'sri')->count();

        return new SriChunkImportResult(
            found: $found,
            imported: $imported,
            updated: $updated,
            skipped: $skipped,
            failed: $failed,
            fromS3: $fromS3,
            fromSri: $fromSri,
        );
    }
}
