<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Contracts\SriAuthorizationGateway;
use App\Domain\Sri\Contracts\SriInvoiceParser;
use App\Domain\Sri\Data\AuthorizationQuery;
use App\Domain\Sri\Data\SriImportResult;
use App\Domain\Sri\Data\SriPeriodBatch;
use App\Domain\Sri\Data\SriRunImportReport;
use App\Domain\Sri\Enums\SriImportOutcome;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Exceptions\SriGatewayException;
use App\Domain\Sri\Exceptions\SriXmlException;
use App\Domain\Sri\Models\SriSyncRun;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Importa los comprobantes de un run en `parsing`.
 *
 * Por cada clave del lote: consulta el XML al SRI (`SriAuthorizationGateway`),
 * lo interpreta (`SriInvoiceParser`) y lo persiste
 * (`PersistSriInvoice`). Los fallos de una clave no detienen el run.
 *
 * | Resultado | Estado del run |
 * |---|---|
 * | Todas las claves guardadas o saltadas | `completed` |
 * | Algunas sí y otras no | `partial` |
 * | Ninguna guardada | `failed` con `error_code=import_failed` |
 *
 * Nunca registra claves completas ni el XML: solo el run, el tipo y conteos.
 */
final readonly class ImportSriRun
{
    public function __construct(
        private SriAuthorizationGateway $gateway,
        private SriInvoiceParser $parser,
        private PersistSriInvoice $persist,
        private ParseSriRun $parse,
    ) {}

    /**
     * @return array{run: SriSyncRun, report: SriRunImportReport}
     */
    public function handle(SriSyncRun $run): array
    {
        if (! in_array($run->status, [SriSyncRunStatus::Parsing, SriSyncRunStatus::Downloading], true)) {
            return ['run' => $run, 'report' => $this->emptyReport($run)];
        }

        $parsed = $this->parse->handle($run);
        $run = $parsed['run'];

        if ($run->status !== SriSyncRunStatus::Parsing) {
            return ['run' => $run, 'report' => $this->emptyReport($run)];
        }

        [$report, $errors] = $this->import($run, $parsed['batches']);

        $run->totals = $report->toTotals();

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

        return ['run' => $run->refresh(), 'report' => $report];
    }

    /**
     * @param  list<SriPeriodBatch>  $batches
     * @return array{SriRunImportReport, list<string>}
     */
    private function import(SriSyncRun $run, array $batches): array
    {
        $company = $run->company()->firstOrFail();
        $found = 0;
        $imported = 0;
        $updated = 0;
        $skipped = 0;
        $failed = 0;
        $errors = [];

        foreach ($batches as $batch) {
            foreach ($batch->accessKeys as $key) {
                $found++;

                try {
                    $result = $this->importKey($run, $company, $key->value);

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
        ]);

        $status = match (true) {
            $failed === 0 => SriSyncRunStatus::Completed,
            ($imported + $updated + $skipped) > 0 => SriSyncRunStatus::Partial,
            default => SriSyncRunStatus::Failed,
        };

        return [
            new SriRunImportReport($found, $imported, $updated, $skipped, $failed, $status),
            $errors,
        ];
    }

    private function importKey(SriSyncRun $run, Company $company, string $accessKey): SriImportResult
    {
        $authorization = $this->gateway->query(new AuthorizationQuery($accessKey));

        if (! $authorization->isAuthorized() || ! $authorization->hasXml()) {
            throw SriXmlException::unreadable();
        }

        $xml = (string) $authorization->xml;
        $data = $this->parser->parse($xml);

        return $this->persist->handle($company, $data, $xml, $run);
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
