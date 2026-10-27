<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Sri\Contracts\SriRobotObjectStore;
use App\Domain\Sri\Data\SriPeriodBatch;
use App\Domain\Sri\Data\SriRobotObjectPath;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriSyncRunFileStatus;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Exceptions\SriRobotObjectNotFound;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Sri\Models\SriSyncRunFile;
use App\Domain\Sri\Parsers\PeriodBatchBuilder;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Interpreta los archivos de un run que la reconciliación dejó en `parsing`.
 *
 * Por cada TXT disponible construye un `SriPeriodBatch` con las claves que se
 * consultarán en el SRI. Marca los archivos como `processed` y cierra el run:
 *
 * - `completed` si todos los lotes tienen claves válidas y son consistentes.
 * - `partial` si hay al menos un lote válido pero otros quedaron vacíos o con
 *   diferencias entre el TXT y el JSON de claves.
 * - `failed` si no se pudo obtener ninguna clave.
 *
 * Todavía no persiste comprobantes: la consulta al SRI y la escritura de
 * `invoices` son de F2-06/F2-08.
 */
final readonly class ParseSriRun
{
    private string $environment;

    public function __construct(
        private SriRobotObjectStore $store,
        private PeriodBatchBuilder $builder,
        private ReconcileSriRun $reconcile,
        string $environment,
    ) {
        $this->environment = strtolower(trim($environment));
    }

    /**
     * @return array{run: SriSyncRun, batches: list<SriPeriodBatch>}
     */
    public function handle(SriSyncRun $run): array
    {
        if ($run->status === SriSyncRunStatus::Parsing) {
            return $this->parse($run);
        }

        if ($run->status !== SriSyncRunStatus::Downloading) {
            return ['run' => $run, 'batches' => []];
        }

        $run = $this->reconcile->handle($run);

        return in_array($run->status, [SriSyncRunStatus::Parsing, SriSyncRunStatus::Downloading], true) && $run->files()->exists()
            ? $this->parse($run)
            : ['run' => $run, 'batches' => []];
    }

    /**
     * @return array{run: SriSyncRun, batches: list<SriPeriodBatch>}
     */
    private function parse(SriSyncRun $run): array
    {
        $ruc = (string) $run->company()->firstOrFail()->ruc;
        $files = $run->files()
            ->where('status', SriSyncRunFileStatus::Available)
            ->whereNotNull('document_type')
            ->where('s3_key', 'like', '%.txt')
            ->get();

        $batches = [];
        $failures = 0;

        foreach ($files as $file) {
            $batch = $this->parseFile($run, $file, $ruc);

            if ($batch === null) {
                $failures++;

                continue;
            }

            if ($batch->isEmpty()) {
                $failures++;

                continue;
            }

            if (! $batch->isConsistent() || $batch->invalidAccessKeys !== []) {
                Log::warning('lote de claves inconsistente', [
                    'run_id' => $run->id,
                    'document_type' => $batch->documentType->value,
                    'period' => $batch->period,
                    'missing' => count($batch->missingAccessKeys),
                    'unlisted' => count($batch->unlistedAccessKeys),
                    'invalid' => count($batch->invalidAccessKeys),
                ]);
            }

            $batches[] = $batch;
        }

        $failures += $this->missingTypes($run, $batches);

        $totals = $run->totals ?? [];
        $totals['found'] = array_sum(array_map(
            static fn (SriPeriodBatch $batch): int => $batch->count(),
            $batches,
        ));

        $run->totals = $totals;
        $run->save();

        $this->finish($run, $batches, $failures);

        return ['run' => $run->refresh(), 'batches' => $batches];
    }

    /**
     * Tipos solicitados en el run para los que no se pudo interpretar ningún
     * TXT. La ausencia de un tipo que el robot debía descargar es un paquete
     * incompleto, no un run completo.
     *
     * @param  list<SriPeriodBatch>  $batches
     */
    private function missingTypes(SriSyncRun $run, array $batches): int
    {
        $requested = array_values(array_unique($run->document_types ?? []));

        if ($requested === []) {
            return 0;
        }

        $parsedTypes = array_values(array_unique(array_map(
            static fn (SriPeriodBatch $batch): string => $batch->documentType->value,
            $batches,
        )));

        return count(array_diff($requested, $parsedTypes));
    }

    /**
     * @param  list<SriPeriodBatch>  $batches
     */
    private function finish(SriSyncRun $run, array $batches, int $failures): void
    {
        if ($batches === []) {
            $run->error_code = 'no_access_keys';
            $run->error_message = 'Los archivos del robot no contienen claves de acceso válidas.';
            $run->transitionTo(SriSyncRunStatus::Failed);

            return;
        }

        $complete = $failures === 0
            && collect($batches)->every(
                static fn (SriPeriodBatch $batch): bool => $batch->isConsistent() && $batch->invalidAccessKeys === [],
            );

        if (! $complete) {
            $run->error_code = 'incomplete_package';
            $run->error_message = 'El paquete del robot tiene archivos vacíos o claves inconsistentes.';
        }

        $run->transitionTo($complete ? SriSyncRunStatus::Completed : SriSyncRunStatus::Partial);
    }

    private function parseFile(SriSyncRun $run, SriSyncRunFile $file, string $ruc): ?SriPeriodBatch
    {
        $type = $file->document_type;

        if (! $type instanceof SriDocumentType || $file->s3_key === null) {
            return null;
        }

        $period = $this->period($file->s3_key, $type);

        if ($period === null) {
            $this->mark($file, SriSyncRunFileStatus::Invalid, 'No se pudo deducir el período del archivo.');

            return null;
        }

        $documentTypes = $run->document_types ?? [];

        if ($documentTypes !== [] && ! in_array($type->value, $documentTypes, true)) {
            $this->mark($file, SriSyncRunFileStatus::Invalid, 'El tipo del archivo no fue solicitado en el run.');

            return null;
        }

        try {
            $contents = $this->store->read($file->s3_key);
            $listed = $this->listedKeys($run, $type, $period);
        } catch (SriRobotObjectNotFound) {
            $this->mark($file, SriSyncRunFileStatus::Missing, 'El archivo ya no existe en S3.');

            return null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        $batch = $this->builder->build(
            contents: $contents,
            period: $period,
            documentType: $type,
            ruc: $ruc,
            listedKeys: $listed,
        );

        $this->mark(
            $file,
            $batch->isEmpty() ? SriSyncRunFileStatus::Invalid : SriSyncRunFileStatus::Processed,
            $batch->isEmpty() ? 'El TXT no contiene claves válidas para el run.' : null,
        );

        return $batch;
    }

    /**
     * Claves declaradas en el JSON de claves del robot.
     *
     * @return list<string>
     */
    private function listedKeys(SriSyncRun $run, SriDocumentType $type, string $period): array
    {
        $path = new SriRobotObjectPath($this->environment, (string) $run->company()->firstOrFail()->ruc, $period);
        $keysKey = $run->files()->where('s3_key', $path->accessKeys($type))->first();

        if ($keysKey === null) {
            return [];
        }

        try {
            $payload = json_decode($this->store->read($keysKey->s3_key), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        $keys = is_array($payload) ? ($payload['clavesAcceso'] ?? null) : null;

        if (! is_array($keys)) {
            return [];
        }

        return array_values(array_filter(
            array_map(static fn (mixed $key): string => trim((string) $key), $keys),
            static fn (string $key): bool => $key !== '',
        ));
    }

    private function period(string $s3Key, SriDocumentType $type): ?string
    {
        $pattern = '/\/(\d{4}-(?:0[1-9]|1[0-2]))\/'
            .preg_quote($type->slug(), '/')
            .'-recibidas-[^\/]*\.txt$/';

        if (preg_match($pattern, $s3Key, $matches) !== 1) {
            return null;
        }

        return $matches[1];
    }

    private function mark(SriSyncRunFile $file, SriSyncRunFileStatus $status, ?string $reason): void
    {
        $file->status = $status;
        $file->error_message = $reason;
        $file->processed_at = now();
        $file->save();
    }
}
