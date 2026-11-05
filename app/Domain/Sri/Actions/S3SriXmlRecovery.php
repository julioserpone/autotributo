<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Sri\Contracts\SriRobotObjectStore;
use App\Domain\Sri\Contracts\SriXmlRecovery;
use App\Domain\Sri\Data\SriRobotObjectPath;
use App\Domain\Sri\Data\SriXmlLocation;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriSyncRunFileStatus;
use App\Domain\Sri\Exceptions\SriRobotObjectNotFound;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Sri\Models\SriSyncRunFile;
use JsonException;
use Throwable;

/**
 * Lee los XML de un run desde el bucket del robot.
 *
 * El robot guarda cada comprobante en
 * `{ambiente}/{ruc}/{periodo}/{tipo}-recibidas-{periodo}-xml/{clave}.xml`.
 * Esa carpeta solo está separada por período, así que antes de leer un XML se
 * verifica el período del run con lo que publica el robot:
 *
 * 1. El `manifest.json` del período coincide en `ambiente`, `ruc` y `periodo`
 *    con el run, y el tipo del comprobante aparece en sus descargas.
 * 2. Si todavía no hay manifiesto, basta con que exista el JSON de claves del
 *    tipo para el período.
 *
 * Sin esa verificación no se toca S3: un run de un período sin descargar no
 * puede terminar leyendo comprobantes de otro mes.
 */
final readonly class S3SriXmlRecovery implements SriXmlRecovery
{
    private const MAX_LISTED_KEYS = 50_000;

    private const MAX_XML_BYTES = 5 * 1024 * 1024;

    private string $environment;

    public function __construct(
        private SriRobotObjectStore $store,
        string $environment,
    ) {
        $this->environment = strtolower(trim($environment));
    }

    public function discover(SriSyncRun $run): bool
    {
        $ruc = $this->ruc($run);
        $periods = $this->periods($run);
        $verified = false;

        foreach ($periods as $period) {
            $path = $this->path($ruc, $period);
            $manifest = $this->manifest($path);

            if ($manifest !== null) {
                $verified = true;

                foreach ($this->declaredTypes($manifest) as $type) {
                    $this->recordIfExists($run, $path->textReport($type), $type);
                    $this->recordIfExists($run, $path->accessKeys($type), $type);
                }
            }

            foreach ($this->requestedTypes($run) as $type) {
                if ($manifest === null && ! $this->store->exists($path->accessKeys($type))) {
                    continue;
                }

                $verified = true;
                $this->discoverXml($run, $path, $type);
            }
        }

        if ($verified) {
            $run->totals = ['xml_found' => $run->files()
                ->where('status', SriSyncRunFileStatus::Available)
                ->where('s3_key', 'like', '%-xml/%')
                ->count(), ...($run->totals ?? [])];
            $run->save();
        }

        return $verified;
    }

    /**
     * Claves de acceso declaradas en los lotes del robot para los períodos y
     * tipos del run. Devuelve `clave => [tipo, período]`.
     *
     * @return array<string, array{string, string}>
     */
    public function accessKeys(SriSyncRun $run): array
    {
        $keys = [];

        foreach ($this->periods($run) as $period) {
            $path = $this->path($this->ruc($run), $period);

            foreach ($this->requestedTypes($run) as $type) {
                foreach ($this->listedAccessKeys($path, $type) as $accessKey) {
                    $keys[$accessKey] = [$type->value, $period];
                }
            }
        }

        return $keys;
    }

    public function find(SriSyncRun $run, SriSyncRunFile $file): ?SriXmlLocation
    {
        $key = $file->s3_key;
        $type = $file->document_type;

        if (! is_string($key) || ! $type instanceof SriDocumentType) {
            return null;
        }

        $period = $this->periodOfXmlKey($key);

        if ($period === null || ! in_array($period, $this->periods($run), true)) {
            return null;
        }

        $path = $this->path($this->ruc($run), $period);

        if (! $this->isVerified($path, $type)) {
            return null;
        }

        try {
            $contents = $this->store->read($key);
        } catch (SriRobotObjectNotFound) {
            return null;
        }

        if ($contents === '' || strlen($contents) > self::MAX_XML_BYTES) {
            return null;
        }

        return new SriXmlLocation($type, $period, $key, hash('sha256', $contents));
    }

    public function contents(SriXmlLocation $location): string
    {
        return $this->store->read($location->key);
    }

    public function mark(SriSyncRun $run, SriSyncRunFile $file, ?SriXmlLocation $location, array $totals): void
    {
        $file->status = $location === null ? SriSyncRunFileStatus::Missing : SriSyncRunFileStatus::Available;
        $file->checksum = $location?->checksum;
        $file->error_message = $location === null
            ? 'El XML no está disponible en el repositorio del robot o el período no está verificado.'
            : null;
        $file->processed_at = $location === null ? null : now();
        $file->save();

        $run->totals = $totals;
        $run->save();
    }

    private function discoverXml(SriSyncRun $run, SriRobotObjectPath $path, SriDocumentType $type): void
    {
        $name = basename($path->xmlDirectory($type));

        // Las claves del lote son el contrato principal: por cada una se sabe
        // si el XML está o falta, sin listar el prefijo.
        foreach ($this->listedAccessKeys($path, $type) as $accessKey) {
            $this->recordIfExists($run, $path->xml($type, $accessKey), $type, "El XML no está en {$name}/.");
        }

        $known = $run->files()->pluck('s3_key')->all();

        // XML que el robot bajó y que el lote no lista: se registran para que
        // la importación los use como respaldo de la consulta al SRI.
        foreach ($this->keysIn($path, $type) as $key) {
            if (in_array($key, $known, true)) {
                continue;
            }

            $this->record($run, $key, $type, SriSyncRunFileStatus::Available);
        }
    }

    /**
     * @return list<string>
     */
    private function keysIn(SriRobotObjectPath $path, SriDocumentType $type): array
    {
        try {
            $keys = $this->store->keys($path->xmlDirectory($type).'/');
        } catch (Throwable $exception) {
            report($exception);

            return [];
        }

        $keys = array_values(array_filter(
            $keys,
            static fn (string $key): bool => str_ends_with($key, '.xml')
                && preg_match('/^\d{49}\.xml$/', basename($key)) === 1,
        ));

        return array_slice($keys, 0, self::MAX_LISTED_KEYS);
    }

    private function recordIfExists(
        SriSyncRun $run,
        string $key,
        SriDocumentType $type,
        string $missing = 'El archivo no existe en S3.',
    ): void {
        if ($this->store->exists($key)) {
            $this->record($run, $key, $type, SriSyncRunFileStatus::Available);

            return;
        }

        $this->record($run, $key, $type, SriSyncRunFileStatus::Missing, $missing);
    }

    private function isVerified(SriRobotObjectPath $path, SriDocumentType $type): bool
    {
        $manifest = $this->manifest($path);

        if ($manifest !== null) {
            return in_array($type, $this->declaredTypes($manifest), true);
        }

        return $this->store->exists($path->accessKeys($type));
    }

    /**
     * @return array<string, mixed>|null
     */
    private function manifest(SriRobotObjectPath $path): ?array
    {
        $key = $path->manifest();

        try {
            if (! $this->store->exists($key)) {
                return null;
            }

            $data = json_decode($this->store->read($key), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException|SriRobotObjectNotFound) {
            return null;
        } catch (Throwable $exception) {
            report($exception);

            return null;
        }

        if (! is_array($data) || ! $this->matchesRun($data, $path)) {
            return null;
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    private function matchesRun(array $manifest, SriRobotObjectPath $path): bool
    {
        return strtolower((string) ($manifest['ambiente'] ?? '')) === $this->environment
            && (string) ($manifest['ruc'] ?? '') === $path->ruc
            && (string) ($manifest['periodo'] ?? '') === $path->period;
    }

    /**
     * @param  array<string, mixed>  $manifest
     * @return list<SriDocumentType>
     */
    private function declaredTypes(array $manifest): array
    {
        $entries = $manifest['descargas'] ?? null;

        if (! is_array($entries)) {
            return [];
        }

        $types = [];

        foreach ($entries as $entry) {
            $code = is_array($entry) ? ($entry['tipo'] ?? null) : null;
            $type = is_string($code) ? SriDocumentType::tryFrom($code) : null;

            if ($type !== null) {
                $types[$type->value] = $type;
            }
        }

        return array_values($types);
    }

    /**
     * @return list<string>
     */
    private function listedAccessKeys(SriRobotObjectPath $path, SriDocumentType $type): array
    {
        $key = $path->accessKeys($type);

        try {
            if (! $this->store->exists($key)) {
                return [];
            }

            $payload = json_decode($this->store->read($key), true, flags: JSON_THROW_ON_ERROR);
        } catch (Throwable) {
            return [];
        }

        $keys = is_array($payload) ? ($payload['clavesAcceso'] ?? null) : null;

        if (! is_array($keys)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => trim((string) $value), $keys),
            static fn (string $value): bool => preg_match('/^\d{49}$/', $value) === 1,
        )));
    }

    private function record(
        SriSyncRun $run,
        string $key,
        SriDocumentType $type,
        SriSyncRunFileStatus $status,
        ?string $error = null,
    ): void {
        SriSyncRunFile::query()->updateOrCreate(
            ['run_id' => $run->id, 's3_key' => $key],
            [
                'file_name' => basename($key),
                'document_type' => $type->value,
                'status' => $status,
                'error_message' => $error,
                'processed_at' => null,
            ],
        );
    }

    private function periodOfXmlKey(string $key): ?string
    {
        $pattern = '/^[a-z0-9-]+\/\d{10,13}\/(\d{4}-(?:0[1-9]|1[0-2]))\/'
            .'[a-z-]+-recibidas-\d{4}-(?:0[1-9]|1[0-2])-xml\/\d{49}\.xml$/';

        return preg_match($pattern, $key, $matches) === 1 ? $matches[1] : null;
    }

    private function path(string $ruc, string $period): SriRobotObjectPath
    {
        return new SriRobotObjectPath($this->environment, $ruc, $period);
    }

    private function ruc(SriSyncRun $run): string
    {
        return (string) $run->company()->firstOrFail()->ruc;
    }

    /**
     * @return list<string>
     */
    private function periods(SriSyncRun $run): array
    {
        $periods = $run->periods;

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

    /**
     * @return list<SriDocumentType>
     */
    private function requestedTypes(SriSyncRun $run): array
    {
        return array_values(array_filter(
            array_map(
                static fn (string $code): ?SriDocumentType => SriDocumentType::tryFrom($code),
                $run->document_types ?? [],
            ),
            static fn (?SriDocumentType $type): bool => $type !== null,
        ));
    }
}
