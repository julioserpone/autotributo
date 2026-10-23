<?php

declare(strict_types=1);

namespace App\Domain\Sri\Contracts;

/**
 * Lectura de los objetos que el robot escribe en su bucket S3.
 *
 * Las claves son las que produce `SriRobotObjectPath`; el prefijo del bucket
 * lo aplica el disco `sri` de Laravel.
 */
interface SriRobotObjectStore
{
    public function exists(string $key): bool;

    public function read(string $key): string;

    public function sha256(string $key): string;
}
