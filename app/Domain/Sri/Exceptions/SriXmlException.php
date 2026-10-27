<?php

declare(strict_types=1);

namespace App\Domain\Sri\Exceptions;

use RuntimeException;
use Throwable;

/**
 * El XML del SRI no se pudo interpretar.
 *
 * El mensaje nunca incluye el contenido del documento (puede contener datos
 * personales); solo el motivo y, cuando aplica, el nombre del campo. El error
 * original del paquete queda como excepción previa para el log.
 */
final class SriXmlException extends RuntimeException
{
    public static function unreadable(?Throwable $previous = null): self
    {
        return new self('El XML del comprobante no es válido.', 0, $previous);
    }

    public static function unsupportedDocumentType(string $type): self
    {
        return new self("El tipo de comprobante no está soportado por el parser: {$type}.");
    }
}
