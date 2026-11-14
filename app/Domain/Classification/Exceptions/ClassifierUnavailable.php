<?php

declare(strict_types=1);

namespace App\Domain\Classification\Exceptions;

use RuntimeException;
use Throwable;

/**
 * El proveedor de IA no está disponible para atender la clasificación.
 *
 * El mensaje es seguro para logs: nunca incluye la descripción del ítem, la
 * respuesta del proveedor ni credenciales.
 */
final class ClassifierUnavailable extends RuntimeException
{
    public static function circuitOpen(): self
    {
        return new self('El circuito del clasificador está abierto; se omite la llamada a la IA.');
    }

    public static function rateLimited(): self
    {
        return new self('El proveedor de IA limitó la tasa de solicitudes.');
    }

    public static function requestFailed(int $status, ?Throwable $previous = null): self
    {
        return new self(
            "El proveedor de IA respondió con un error HTTP {$status}.",
            0,
            $previous,
        );
    }

    public static function connectionFailed(Throwable $previous): self
    {
        return new self(
            'No fue posible conectar con el proveedor de IA (timeout o red).',
            0,
            $previous,
        );
    }

    public static function missingApiKey(string $provider): self
    {
        return new self("Falta la clave de API del proveedor de IA ({$provider}).");
    }
}
