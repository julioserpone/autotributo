<?php

declare(strict_types=1);

namespace App\Integrations\Sri;

use App\Domain\Sri\Contracts\SriRobotObjectStore;
use App\Domain\Sri\Exceptions\SriGatewayException;
use App\Domain\Sri\Exceptions\SriRobotObjectNotFound;
use Illuminate\Contracts\Filesystem\Filesystem;

/**
 * Adaptador del disco `sri` de Laravel (S3 en staging/producción).
 *
 * No escribe objetos: la app consume lo que publica el robot. El checksum se
 * calcula al leer porque el manifiesto actual del robot no lo incluye.
 */
final readonly class FilesystemSriRobotObjectStore implements SriRobotObjectStore
{
    public function __construct(private Filesystem $disk) {}

    public function exists(string $key): bool
    {
        return $this->disk->exists($key);
    }

    public function read(string $key): string
    {
        if (! $this->disk->exists($key)) {
            throw SriRobotObjectNotFound::forKey($key);
        }

        $contents = $this->disk->get($key);

        if (! is_string($contents)) {
            throw SriGatewayException::robotObjectUnreadable();
        }

        return $contents;
    }

    public function sha256(string $key): string
    {
        return hash('sha256', $this->read($key));
    }
}
