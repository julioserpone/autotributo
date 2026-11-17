<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Sri\Contracts\SriRobotObjectStore;
use App\Domain\Sri\Data\SriAccessKey;
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
use InvalidArgumentException;
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
        string $environment,
        private RecoverSriRunFromS3 $recovery,
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

        if (! in_array($run->status, [SriSyncRunStatus::Downloading, SriSyncRunStatus::Invoking], true)) {
            return ['run' => $run, 'batches' => []];
        }

        // La recuperación desde S3 verifica el período del run antes de leer
        // nada. Cubre el caso en que el callback no llega (túnel caído) o el
        // manifiesto aún no está escrito: si el robot ya dejó el lote en S3,
        // la importación puede continuar igual.
        $run = $this->recovery->handle($run);

        return $run->status === SriSyncRunStatus::Parsing && $run->files()->exists()
            ? $this->parse($run)
            : ['run' => $run, 'batches' => []];
    }

    /**
     * @return array{run: SriSyncRun, batches: list<SriPeriodBatch>}
     */
    private function parse(SriSyncRun $run): array
    {
        $files = $run->files()
            ->whereIn('status', [SriSyncRunFileStatus::Available, SriSyncRunFileStatus::Processed])
            ->whereNotNull('document_type')
            ->where('s3_key', 'like', '%.txt')
            ->get();

        $batches = [];
        $failures = 0;

        foreach ($files as $file) {
            $batch = $this->parseFile($run, $file);

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

        // El robot puede descargar los XML de un período sin publicar un TXT o
        // un JSON de claves válidos (el portal a veces devuelve el listado sin
        // claves). En ese caso los XML ya están verificados contra el
        // manifiesto y la importación puede continuar con ellos.
        foreach ($this->xmlBatches($run, $batches) as $batch) {
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
     * Lotes para los períodos y tipos que no tienen TXT válido pero sí XML
     * descargados por el robot.
     *
     * El nombre de cada XML es la clave de acceso del comprobante. Se valida
     * igual que una línea del TXT: tipo y período deben coincidir con el run.
     * El RUC de la clave es el emisor del comprobante (el proveedor en los
     * recibidos), así que no se compara con el de la empresa: la carpeta del
     * bucket y el manifiesto ya garantizan a quién pertenece la descarga. Los
     * XML no reemplazan a un TXT válido: solo cubren los pares período/tipo
     * que quedaron sin lote.
     *
     * @param  list<SriPeriodBatch>  $batches
     * @return list<SriPeriodBatch>
     */
    private function xmlBatches(SriSyncRun $run, array $batches): array
    {
        $covered = [];

        foreach ($batches as $batch) {
            $covered[$batch->period.'|'.$batch->documentType->value] = true;
        }

        $requested = $run->document_types ?? [];

        $files = $run->files()
            ->whereIn('status', [SriSyncRunFileStatus::Available, SriSyncRunFileStatus::Processed])
            ->where('s3_key', 'like', '%-xml/%')
            ->get();

        /** @var array<string, array{list<SriAccessKey>, list<string>}> $grouped */
        $grouped = [];

        foreach ($files as $file) {
            $type = $file->document_type;

            if (! $type instanceof SriDocumentType || $file->s3_key === null) {
                continue;
            }

            if ($requested !== [] && ! in_array($type->value, $requested, true)) {
                continue;
            }

            $period = $this->periodOfXmlKey($file->s3_key);

            if ($period === null) {
                continue;
            }

            $unit = $period.'|'.$type->value;

            if (isset($covered[$unit])) {
                continue;
            }

            $grouped[$unit] ??= [[], []];

            $key = basename($file->s3_key, '.xml');

            try {
                $accessKey = new SriAccessKey($key);
            } catch (InvalidArgumentException) {
                $grouped[$unit][1][] = $key;

                continue;
            }

            if ($accessKey->documentType !== $type
                || $accessKey->period() !== substr($period, 0, 7)) {
                $grouped[$unit][1][] = $key;

                continue;
            }

            $grouped[$unit][0][] = $accessKey;
        }

        $result = [];

        foreach ($grouped as $unit => [$keys, $invalid]) {
            if ($keys === []) {
                continue;
            }

            [$period, $typeCode] = explode('|', $unit, 2);
            $type = SriDocumentType::tryFrom($typeCode);

            if ($type === null) {
                continue;
            }

            $result[] = new SriPeriodBatch(
                period: $period,
                documentType: $type,
                accessKeys: $keys,
                invalidAccessKeys: $invalid,
                totalLines: count($keys) + count($invalid),
            );
        }

        return $result;
    }

    private function periodOfXmlKey(string $s3Key): ?string
    {
        $pattern = '/\/(\d{4}-(?:0[1-9]|1[0-2]))\/'
            .'[a-z-]+-recibidas-\d{4}-(?:0[1-9]|1[0-2])-xml\/\d{49}\.xml$/';

        return preg_match($pattern, $s3Key, $matches) === 1 ? $matches[1] : null;
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
            $run->save();
        }

        // El run se queda en `parsing` a propósito: el veredicto final lo da
        // `ImportSriRun` cuando ya hay comprobantes guardados. Cerrarlo aquí
        // haría que la importación encontrara el run final y no hiciera nada.
    }

    private function parseFile(SriSyncRun $run, SriSyncRunFile $file): ?SriPeriodBatch
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
