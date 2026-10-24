<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Sri\Contracts\SriRobotObjectStore;
use App\Domain\Sri\Data\SriRobotObjectPath;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriSyncRunFileStatus;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Exceptions\SriRobotObjectNotFound;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Sri\Models\SriSyncRunFile;
use JsonException;

/**
 * Reconciliación de un run contra los objetos que publica el robot en S3.
 *
 * El robot no envía callback: la fuente de verdad es su manifiesto. Por cada
 * período se valida el manifiesto, se localizan el TXT y el JSON de claves, se
 * calcula su SHA-256 y se registran en `sri_sync_run_files`.
 *
 * Si todavía no hay manifiesto, el run sigue en `invoking`. Cuando aparece al
 * menos uno, pasa a `downloading`. Solo cuando todos los períodos y archivos
 * están disponibles y válidos pasa a `parsing`, que es el estado que consumirá
 * el parser de F2-06.
 */
final readonly class ReconcileSriRun
{
    private string $environment;

    public function __construct(
        private SriRobotObjectStore $store,
        string $environment,
    ) {
        $this->environment = strtolower(trim($environment));
    }

    public function handle(SriSyncRun $run): SriSyncRun
    {
        if (! $run->isActive() || $run->status === SriSyncRunStatus::Queued) {
            return $run;
        }

        $company = $run->company()->firstOrFail();
        $periods = $this->periods($run);

        if ($periods === []) {
            return $run;
        }

        $manifestsFound = false;
        $allComplete = true;

        foreach ($periods as $period) {
            $path = new SriRobotObjectPath($this->environment, $company->ruc, $period);
            $manifestKey = $path->manifest();

            if (! $this->store->exists($manifestKey)) {
                $allComplete = false;

                continue;
            }

            $manifestsFound = true;
            $manifest = $this->readManifest($run, $manifestKey);

            if ($manifest === null) {
                $allComplete = false;

                continue;
            }

            $data = $manifest['data'];

            if (! $this->manifestMatches($data, $company->ruc, $period)) {
                $this->recordFile(
                    $run,
                    $manifestKey,
                    null,
                    SriSyncRunFileStatus::Invalid,
                    $manifest['checksum'],
                    'El manifiesto no corresponde a la empresa, ambiente o período del run.',
                );
                $allComplete = false;

                continue;
            }

            $this->recordFile(
                $run,
                $manifestKey,
                null,
                SriSyncRunFileStatus::Available,
                $manifest['checksum'],
            );

            $entries = $data['descargas'] ?? null;

            if (! is_array($entries)) {
                $this->recordFile(
                    $run,
                    $manifestKey,
                    null,
                    SriSyncRunFileStatus::Invalid,
                    $manifest['checksum'],
                    'El manifiesto no tiene una lista de descargas válida.',
                );
                $allComplete = false;

                continue;
            }

            $periodComplete = true;

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    $periodComplete = false;

                    continue;
                }

                $type = $this->documentType($entry);

                if ($type === null) {
                    $periodComplete = false;

                    continue;
                }

                $txtKey = $path->textReport($type);
                $txtOk = $this->reconcileText($run, $txtKey, $type, $entry);
                $keysOk = $this->reconcileKeys(
                    $run,
                    $path->accessKeys($type),
                    $type,
                    $company->ruc,
                    $period,
                    $txtKey,
                );

                $periodComplete = $periodComplete && $txtOk && $keysOk;
            }

            $allComplete = $allComplete && $periodComplete;
        }

        if ($manifestsFound && $run->status === SriSyncRunStatus::Invoking) {
            $run->transitionTo(SriSyncRunStatus::Downloading);
        }

        if ($manifestsFound && $allComplete && $run->status->canTransitionTo(SriSyncRunStatus::Parsing)) {
            $run->transitionTo(SriSyncRunStatus::Parsing);
        }

        return $run->refresh();
    }

    /**
     * @return array{data: array<string, mixed>, checksum: string}|null
     */
    private function readManifest(SriSyncRun $run, string $key): ?array
    {
        try {
            $contents = $this->store->read($key);
        } catch (SriRobotObjectNotFound) {
            $this->recordFile($run, $key, null, SriSyncRunFileStatus::Missing, null, 'El manifiesto no existe en S3.');

            return null;
        }

        $checksum = hash('sha256', $contents);

        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->recordFile(
                $run,
                $key,
                null,
                SriSyncRunFileStatus::Invalid,
                $checksum,
                'El manifiesto no contiene JSON válido.',
            );

            return null;
        }

        if (! is_array($data)) {
            $this->recordFile(
                $run,
                $key,
                null,
                SriSyncRunFileStatus::Invalid,
                $checksum,
                'El manifiesto no contiene un objeto JSON.',
            );

            return null;
        }

        return ['data' => $data, 'checksum' => $checksum];
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function reconcileText(
        SriSyncRun $run,
        string $key,
        SriDocumentType $type,
        array $entry,
    ): bool {
        try {
            $contents = $this->store->read($key);
        } catch (SriRobotObjectNotFound) {
            $this->recordFile($run, $key, $type, SriSyncRunFileStatus::Missing, null, 'El TXT no existe en S3.');

            return false;
        }

        $bytes = $entry['bytes'] ?? null;

        if (is_int($bytes) && $bytes >= 0 && $bytes !== strlen($contents)) {
            $this->recordFile(
                $run,
                $key,
                $type,
                SriSyncRunFileStatus::Invalid,
                hash('sha256', $contents),
                "El TXT no coincide con los bytes del manifiesto ({$bytes}).",
            );

            return false;
        }

        $this->recordFile(
            $run,
            $key,
            $type,
            SriSyncRunFileStatus::Available,
            hash('sha256', $contents),
        );

        return true;
    }

    private function reconcileKeys(
        SriSyncRun $run,
        string $key,
        SriDocumentType $type,
        string $ruc,
        string $period,
        string $txtKey,
    ): bool {
        try {
            $contents = $this->store->read($key);
        } catch (SriRobotObjectNotFound) {
            $this->recordFile($run, $key, $type, SriSyncRunFileStatus::Missing, null, 'El JSON de claves no existe en S3.');

            return false;
        }

        $checksum = hash('sha256', $contents);

        try {
            $data = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            $this->recordFile(
                $run,
                $key,
                $type,
                SriSyncRunFileStatus::Invalid,
                $checksum,
                'El JSON de claves no contiene JSON válido.',
            );

            return false;
        }

        if (! is_array($data)) {
            $this->recordFile(
                $run,
                $key,
                $type,
                SriSyncRunFileStatus::Invalid,
                $checksum,
                'El JSON de claves no contiene un objeto JSON.',
            );

            return false;
        }

        $total = $data['total'] ?? null;
        $keys = $data['clavesAcceso'] ?? null;
        $expectedFile = basename($txtKey);

        if (
            ! is_int($total)
            || ! is_array($keys)
            || $total !== count($keys)
            || strtolower((string) ($data['ambiente'] ?? '')) !== $this->environment
            || ($data['ruc'] ?? null) !== $ruc
            || ($data['periodo'] ?? null) !== $period
            || ($data['tipo'] ?? null) !== $type->value
            || ($data['archivo'] ?? null) !== $expectedFile
        ) {
            $this->recordFile(
                $run,
                $key,
                $type,
                SriSyncRunFileStatus::Invalid,
                $checksum,
                'El JSON de claves no coincide con el manifiesto del robot.',
            );

            return false;
        }

        $this->recordFile(
            $run,
            $key,
            $type,
            SriSyncRunFileStatus::Available,
            $checksum,
        );

        return true;
    }

    /**
     * @param  array<string, mixed>  $entry
     */
    private function documentType(array $entry): ?SriDocumentType
    {
        $code = $entry['tipo'] ?? null;

        return is_string($code) ? SriDocumentType::tryFrom($code) : null;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function manifestMatches(array $data, string $ruc, string $period): bool
    {
        return strtolower((string) ($data['ambiente'] ?? '')) === $this->environment
            && ($data['ruc'] ?? null) === $ruc
            && ($data['periodo'] ?? null) === $period;
    }

    /**
     * @return list<string>
     */
    private function periods(SriSyncRun $run): array
    {
        $periods = $run->getAttribute('periods');

        if (is_array($periods) && $periods !== []) {
            return array_values(array_map(
                static fn (mixed $period): string => (string) $period,
                $periods,
            ));
        }

        $cursor = $run->date_from->toImmutable()->startOfMonth();
        $last = $run->date_to->toImmutable()->startOfMonth();
        $periods = [];

        while ($cursor->lessThanOrEqualTo($last)) {
            $periods[] = $cursor->format('Y-m');
            $cursor = $cursor->addMonth();
        }

        return $periods;
    }

    private function recordFile(
        SriSyncRun $run,
        string $key,
        ?SriDocumentType $type,
        SriSyncRunFileStatus $status,
        ?string $checksum = null,
        ?string $error = null,
    ): void {
        SriSyncRunFile::query()->updateOrCreate(
            [
                'run_id' => $run->id,
                's3_key' => $key,
            ],
            [
                'file_name' => basename($key),
                'checksum' => $checksum,
                'document_type' => $type?->value,
                'status' => $status,
                'error_message' => $error,
                'processed_at' => null,
            ],
        );
    }
}
