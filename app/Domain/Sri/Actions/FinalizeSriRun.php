<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Actions\DetectFiscalRisks;
use App\Domain\Sri\Data\SriChunkImportResult;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriSyncRunFileStatus;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use Throwable;

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

        // Segunda pasada: los XML que llegaron a S3 después de que su bloque
        // de importación ya había corrido quedaban como `available` sin
        // importarse. Antes de cerrar, se reconcilia contra S3 y se importan
        // esos archivos para que no se pierdan ni queden sin resultado. Es
        // best-effort: si S3 no está disponible (p. ej. en tests), se omite.
        if ($run->status === SriSyncRunStatus::Parsing || $run->status === SriSyncRunStatus::Downloading) {
            try {
                $reconcile = app(ReconcileSriRun::class);
                $run = $reconcile->handle($run);

                if ($run->status === SriSyncRunStatus::Parsing) {
                    $this->importLateFiles($run, app(ImportSriKeys::class));
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if ($run->status->isFinal()) {
            return $run->refresh();
        }

        if ($run->status !== SriSyncRunStatus::Parsing) {
            return $run->refresh();
        }

        if ($result === null) {
            $result = $this->aggregateFromFiles($run);
        }

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

        if ($status === SriSyncRunStatus::Completed || $status === SriSyncRunStatus::Partial) {
            try {
                $company = Company::query()->find($run->company_id);

                if ($company instanceof Company) {
                    app(DetectFiscalRisks::class)->handle($company);
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $run->refresh();
    }

    /**
     * Importa los XML que llegaron tarde a S3 y quedaron como `available` sin
     * resultado. Evita que un comprobante válido se quede sin procesar solo
     * porque su bloque ya había corrido.
     */
    private function importLateFiles(SriSyncRun $run, ImportSriKeys $keys): void
    {
        $files = $run->files()
            ->where('s3_key', 'like', '%-xml/%')
            ->where('status', SriSyncRunFileStatus::Available)
            ->whereNull('import_outcome')
            ->get();

        if ($files->isEmpty()) {
            return;
        }

        $groups = [];

        foreach ($files as $file) {
            $type = $file->document_type;

            if (! $type instanceof SriDocumentType || $file->s3_key === null) {
                continue;
            }

            $period = $this->periodOfXmlKey($file->s3_key);

            if ($period === null) {
                continue;
            }

            $accessKey = basename((string) $file->file_name, '.xml');

            if ($accessKey === '' || $accessKey === '.xml') {
                continue;
            }

            $groups[$period.'|'.$type->value] ??= [
                'period' => $period,
                'type' => $type,
                'keys' => [],
            ];

            $groups[$period.'|'.$type->value]['keys'][] = $accessKey;
        }

        foreach ($groups as $group) {
            try {
                $keys->handle($run, $group['type'], $group['period'], $group['keys']);
            } catch (Throwable $exception) {
                report($exception);
            }
        }
    }

    private function periodOfXmlKey(string $s3Key): ?string
    {
        $pattern = '/\/(\d{4}-(?:0[1-9]|1[0-2]))\/'
            .'[a-z-]+-recibidas-\d{4}-(?:0[1-9]|1[0-2])-xml\/\d{49}\.xml$/';

        return preg_match($pattern, $s3Key, $matches) === 1 ? $matches[1] : null;
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
