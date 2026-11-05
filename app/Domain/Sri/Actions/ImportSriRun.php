<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Contracts\SriAuthorizationGateway;
use App\Domain\Sri\Contracts\SriInvoiceParser;
use App\Domain\Sri\Contracts\SriXmlRecovery;
use App\Domain\Sri\Data\AuthorizationQuery;
use App\Domain\Sri\Data\SriAccessKey;
use App\Domain\Sri\Data\SriImportResult;
use App\Domain\Sri\Data\SriPeriodBatch;
use App\Domain\Sri\Data\SriRunImportReport;
use App\Domain\Sri\Data\SriXmlLocation;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriImportOutcome;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Exceptions\SriGatewayException;
use App\Domain\Sri\Exceptions\SriXmlException;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Sri\Models\SriSyncRunFile;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Importa los comprobantes de un run en `parsing`.
 *
 * Por cada clave del lote el XML se obtiene, en este orden:
 *
 * 1. **Repositorio S3 del robot**: si el run ya publicó sus comprobantes en
 *    el bucket, se leen de `{periodo}/{tipo}-recibidas-{periodo}-xml/`. Esta
 *    es la vía principal porque no depende del SRI ni del callback.
 * 2. **Consulta al SRI** (`SriAuthorizationGateway`): respaldo cuando el robot
 *    no guardó ese comprobante. Se puede desactivar con
 *    `sri.authorization.fallback_to_sri=false`.
 *
 * El XML se interpreta (`SriInvoiceParser`) y se persiste
 * (`PersistSriInvoice`). Los fallos de una clave no detienen el run.
 *
 * | Resultado | Estado del run |
 * |---|---|
 * | Todas las claves guardadas o saltadas | `completed` |
 * | Algunas sí y otras no | `partial` |
 * | Ninguna guardada | `failed` con `error_code=import_failed` |
 * | El robot no tiene comprobantes del período | `completed` con `found=0` |
 *
 * Nunca registra claves completas ni el XML: solo el run, el tipo y conteos.
 */
final readonly class ImportSriRun
{
    private bool $fallbackToSri;

    private int $sriWindowDays;

    public function __construct(
        private SriAuthorizationGateway $gateway,
        private SriInvoiceParser $parser,
        private PersistSriInvoice $persist,
        private ParseSriRun $parse,
        private SriXmlRecovery $recovery,
        bool $fallbackToSri = true,
        int $sriWindowDays = 45,
    ) {
        $this->fallbackToSri = $fallbackToSri;
        $this->sriWindowDays = $sriWindowDays;
    }

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

        [$report, $errors, $sources] = $this->import($run, $parsed['batches']);

        $run->totals = [...$report->toTotals(), ...$sources];

        if ($errors !== []) {
            $run->error_message = sprintf(
                '%d comprobante(s) no se pudieron importar: %s',
                count($errors),
                implode(', ', array_slice($errors, 0, 3)),
            );
        }

        if ($report->failed > 0 && $report->stored() === 0) {
            $run->error_code = 'import_failed';
        } elseif ($report->status === SriSyncRunStatus::Completed) {
            $run->error_code = null;
            $run->error_message = null;
        }

        $run->transitionTo($report->status);
        $run->save();

        return [
            'run' => $run->refresh(),
            'report' => $report,
            'sources' => ['from_s3' => $sources['from_s3'], 'from_sri' => $sources['from_sri']],
        ];
    }

    /**
     * @return array{from_s3: int, from_sri: int}
     */
    private function emptySources(): array
    {
        return ['from_s3' => 0, 'from_sri' => 0];
    }

    /**
     * @param  list<SriPeriodBatch>  $batches
     * @return array{SriRunImportReport, list<string>, array{from_s3: int, from_sri: int}}
     */
    private function import(SriSyncRun $run, array $batches): array
    {
        $company = $run->company()->firstOrFail();
        $found = 0;
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $fromS3 = 0;
        $fromSri = 0;
        $errors = [];

        foreach ($batches as $batch) {
            foreach ($batch->accessKeys as $key) {
                $found++;

                try {
                    [$result, $source] = $this->importKey($run, $company, $batch->documentType, $key->value);
                    $source === 's3' ? $fromS3++ : $fromSri++;

                    match ($result->outcome) {
                        SriImportOutcome::Created => $imported++,
                        SriImportOutcome::Updated => $updated++,
                        SriImportOutcome::Skipped => $skipped++,
                    };
                } catch (SriGatewayException $exception) {
                    $failed++;
                    $errors[] = 'sri_unavailable';
                    report($exception);
                } catch (SriXmlException $exception) {
                    $failed++;
                    $errors[] = 'invalid_xml';
                    report($exception);
                } catch (Throwable $exception) {
                    $failed++;
                    $errors[] = 'unexpected_error';
                    report($exception);
                }
            }
        }

        Log::info('run de importación finalizado', [
            'run_id' => $run->id,
            'found' => $found,
            'imported' => $imported,
            'updated' => $updated,
            'skipped' => $skipped,
            'failed' => $failed,
            'from_s3' => $fromS3,
            'from_sri' => $fromSri,
        ]);

        $status = match (true) {
            $failed === 0 => SriSyncRunStatus::Completed,
            ($imported + $updated + $skipped) > 0 => SriSyncRunStatus::Partial,
            default => SriSyncRunStatus::Failed,
        };

        return [
            new SriRunImportReport($found, $imported, $updated, $skipped, $failed, $status),
            $errors,
            ['from_s3' => $fromS3, 'from_sri' => $fromSri],
        ];
    }

    /**
     * El endpoint de autorizaciones del SRI responde bien solo para
     * comprobantes recientes (observado: hasta 50 días). Dentro de la ventana
     * se consulta primero al SRI; fuera de ella, o si el SRI falla, se usa S3.
     *
     * @return array{SriImportResult, 's3'|'sri'}
     */
    private function importKey(
        SriSyncRun $run,
        Company $company,
        SriDocumentType $type,
        string $accessKey,
    ): array {
        $file = $this->fileFor($run, $type, $accessKey);
        $location = null;

        if ($this->fallbackToSri && $this->insideSriWindow($accessKey)) {
            try {
                $xml = $this->querySri($accessKey);
                $source = 'sri';
            } catch (SriGatewayException|SriXmlException $exception) {
                report($exception);
                [$xml, $location] = $this->fromS3($run, $file, $accessKey);
                $source = 's3';
            }
        } else {
            [$xml, $location] = $this->fromS3($run, $file, $accessKey);
            $source = 's3';
        }

        $data = $this->parser->parse($xml);
        $result = $this->persist->handle($company, $data, $xml, $run);

        if ($location !== null && $file !== null) {
            $this->recovery->mark($run, $file, $location, $run->totals ?? []);
        }

        return [$result, $source];
    }

    /**
     * XML desde el repositorio del robot. Si no existe, el motivo es seguro:
     * la clave no está publicada, el período no está verificado o el respaldo
     * al SRI está desactivado.
     *
     * @return array{string, SriXmlLocation|null}
     */
    private function fromS3(SriSyncRun $run, ?SriSyncRunFile $file, string $accessKey): array
    {
        // Si el XML ya está publicado en S3 esta ruta lo devuelve; el llamador
        // decide el orden entre SRI y S3 según la ventana de 45 días.
        $location = $file === null || ! $file->document_type instanceof SriDocumentType
            ? null
            : $this->location($run, $file->document_type, $accessKey);

        if ($location !== null) {
            return [$this->recovery->contents($location), $location];
        }

        throw SriXmlException::unreadable();
    }

    /**
     * ¿La fecha de emisión de la clave está dentro de la ventana en la que el
     * endpoint del SRI devuelve el XML? Fechas inválidas o futuras se tratan
     * como fuera de la ventana y se resuelven desde S3.
     */
    private function insideSriWindow(string $accessKey): bool
    {
        try {
            $issuedAt = (new SriAccessKey($accessKey))->issuedAt;
        } catch (InvalidArgumentException) {
            return false;
        }

        $today = now()->startOfDay();
        $days = $today->greaterThanOrEqualTo($issuedAt)
            ? (int) $issuedAt->diff($today)->days
            : -1;

        return $days >= 0 && $days <= $this->sriWindowDays;
    }

    /**
     * Busca el XML en el repositorio del robot, si el período está verificado.
     */
    private function location(SriSyncRun $run, SriDocumentType $type, string $accessKey): ?SriXmlLocation
    {
        $file = $this->fileFor($run, $type, $accessKey);

        if ($file === null) {
            return null;
        }

        try {
            $location = $this->recovery->find($run, $file);
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        return $location;
    }

    private function fileFor(SriSyncRun $run, SriDocumentType $type, string $accessKey): ?SriSyncRunFile
    {
        return $run->files()
            ->where('document_type', $type)
            ->where('s3_key', 'like', '%-xml/%')
            ->where('file_name', $accessKey.'.xml')
            ->first();
    }

    private function querySri(string $accessKey): string
    {
        $authorization = $this->gateway->query(new AuthorizationQuery($accessKey));

        if (! $authorization->isAuthorized() || ! $authorization->hasXml()) {
            throw SriXmlException::unreadable();
        }

        return (string) $authorization->xml;
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
