<?php

declare(strict_types=1);

namespace App\Domain\Sri\Exceptions;

use RuntimeException;

/**
 * Ya existe un run activo para la empresa.
 *
 * La aplicación no permite dos descargas simultáneas del mismo contribuyente:
 * el portal del SRI puede bloquear la cuenta por intentos concurrentes.
 */
final class SriSyncAlreadyRunning extends RuntimeException
{
    public function __construct(public readonly ?string $runPublicId = null)
    {
        parent::__construct(
            $runPublicId === null
                ? 'Ya hay una sincronización activa para la empresa.'
                : "Ya hay una sincronización activa para la empresa (run {$runPublicId}).",
        );
    }

    public static function forRun(string $runPublicId): self
    {
        return new self($runPublicId);
    }
}
