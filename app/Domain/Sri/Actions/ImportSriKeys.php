<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Contracts\SriAuthorizationGateway;
use App\Domain\Sri\Contracts\SriInvoiceParser;
use App\Domain\Sri\Contracts\SriXmlRecovery;
use App\Domain\Sri\Data\AuthorizationQuery;
use App\Domain\Sri\Data\SriAccessKey;
use App\Domain\Sri\Data\SriChunkImportResult;
use App\Domain\Sri\Data\SriImportResult;
use App\Domain\Sri\Data\SriRobotObjectPath;
use App\Domain\Sri\Data\SriXmlLocation;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriImportOutcome;
use App\Domain\Sri\Enums\SriSyncRunFileStatus;
use App\Domain\Sri\Exceptions\SriGatewayException;
use App\Domain\Sri\Exceptions\SriXmlException;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Sri\Models\SriSyncRunFile;
use InvalidArgumentException;
use Throwable;

/**
 * Importa un subconjunto (lote) de claves de acceso de un run.
 *
 * Cada clave se consulta (S3 o SRI), se parsea y se persiste. El resultado
 * por comprobante se registra de forma idempotente en `sri_sync_run_files`.
 */
final readonly class ImportSriKeys
{
    public function __construct(
        private SriAuthorizationGateway $gateway,
        private SriInvoiceParser $parser,
        private PersistSriInvoice $persist,
        private SriXmlRecovery $recovery,
        private string $environment,
        private bool $fallbackToSri = true,
        private int $sriWindowDays = 45,
    ) {}

    /**
     * @param  list<string>  $keys
     */
    public function handle(
        SriSyncRun $run,
        SriDocumentType $type,
        string $period,
        array $keys,
    ): SriChunkImportResult {
        $company = $run->company()->firstOrFail();
        $found = 0;
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $fromS3 = 0;
        $fromSri = 0;

        foreach ($keys as $accessKey) {
            $found++;

            try {
                [$result, $source] = $this->importKey($run, $company, $type, $accessKey);
                $source === 's3' ? $fromS3++ : $fromSri++;

                match ($result->outcome) {
                    SriImportOutcome::Created => $imported++,
                    SriImportOutcome::Updated => $updated++,
                    SriImportOutcome::Skipped => $skipped++,
                };

                $this->recordSuccess($run, $company, $type, $period, $accessKey, $result->outcome, $source);
            } catch (SriGatewayException $exception) {
                $failed++;
                report($exception);
                $this->recordFailure($run, $company, $type, $period, $accessKey, 'sri_unavailable', 'El SRI no respondió o no autorizó el comprobante.', SriSyncRunFileStatus::Missing);
            } catch (SriXmlException $exception) {
                $failed++;
                report($exception);
                $this->recordFailure($run, $company, $type, $period, $accessKey, 'invalid_xml', 'El XML del comprobante es ilegible o malformado.', SriSyncRunFileStatus::Invalid);
            } catch (Throwable $exception) {
                $failed++;
                report($exception);
                $this->recordFailure($run, $company, $type, $period, $accessKey, 'unexpected_error', 'Ocurrió un error inesperado al procesar el comprobante.', SriSyncRunFileStatus::Invalid);
            }
        }

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

    /**
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
            } catch (SriGatewayException $exception) {
                report($exception);

                try {
                    [$xml, $location] = $this->fromS3($run, $file, $accessKey);
                } catch (SriXmlException $fallback) {
                    // Fallaron las dos fuentes: el motivo principal es que el
                    // SRI no respondió, no que el XML esté malformado.
                    throw SriGatewayException::authorizationUnavailable($fallback);
                }

                $source = 's3';
            } catch (SriXmlException $exception) {
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

    /**
     * @return array{string, SriXmlLocation|null}
     */
    private function fromS3(SriSyncRun $run, ?SriSyncRunFile $file, string $accessKey): array
    {
        if ($file === null || ! $file->document_type instanceof SriDocumentType) {
            throw SriXmlException::unreadable();
        }

        try {
            $location = $this->recovery->find($run, $file);
        } catch (Throwable $exception) {
            report($exception);

            throw SriXmlException::unreadable();
        }

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

    private function recordSuccess(
        SriSyncRun $run,
        Company $company,
        SriDocumentType $type,
        string $period,
        string $accessKey,
        SriImportOutcome $outcome,
        string $source,
    ): void {
        $path = new SriRobotObjectPath($this->environment, (string) $company->ruc, $period);
        $s3Key = $path->xml($type, $accessKey);

        SriSyncRunFile::query()->updateOrCreate(
            [
                'run_id' => $run->id,
                's3_key' => $s3Key,
            ],
            [
                'file_name' => $accessKey.'.xml',
                'document_type' => $type,
                'status' => SriSyncRunFileStatus::Processed,
                'error_code' => null,
                'error_message' => null,
                'import_outcome' => $outcome,
                'source' => $source,
                'processed_at' => now(),
            ],
        );
    }

    private function recordFailure(
        SriSyncRun $run,
        Company $company,
        SriDocumentType $type,
        string $period,
        string $accessKey,
        string $errorCode,
        string $errorMessage,
        SriSyncRunFileStatus $status,
    ): void {
        $path = new SriRobotObjectPath($this->environment, (string) $company->ruc, $period);
        $s3Key = $path->xml($type, $accessKey);

        SriSyncRunFile::query()->updateOrCreate(
            [
                'run_id' => $run->id,
                's3_key' => $s3Key,
            ],
            [
                'file_name' => $accessKey.'.xml',
                'document_type' => $type,
                'status' => $status,
                'error_code' => $errorCode,
                'error_message' => $errorMessage,
                'import_outcome' => null,
                'source' => null,
                'processed_at' => now(),
            ],
        );
    }
}
