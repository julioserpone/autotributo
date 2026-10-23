<?php

declare(strict_types=1);

namespace App\Domain\Sri\Exceptions;

use RuntimeException;

/**
 * El objeto esperado del robot todavía no existe en S3.
 */
final class SriRobotObjectNotFound extends RuntimeException
{
    public static function forKey(string $key): self
    {
        return new self("No existe el objeto del robot SRI: {$key}.");
    }
}
