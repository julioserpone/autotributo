<?php

declare(strict_types=1);

namespace App\Domain\Sri\Exceptions;

use RuntimeException;
use Throwable;

/**
 * Error de integración con el SRI o con el robot.
 *
 * El mensaje es seguro para logs: nunca incluye el XML, credenciales ni la
 * clave de acceso completa.
 */
final class SriGatewayException extends RuntimeException
{
    public static function authorizationUnavailable(Throwable $previous): self
    {
        return new self(
            'No fue posible consultar la autorización del comprobante en el SRI.',
            0,
            $previous,
        );
    }

    public static function robotInvocationFailed(string $functionName, Throwable $previous): self
    {
        return new self(
            "No fue posible invocar el robot SRI ({$functionName}).",
            0,
            $previous,
        );
    }

    public static function robotObjectUnreadable(): self
    {
        return new self('No fue posible leer un objeto del robot SRI.');
    }
}
