<?php

declare(strict_types=1);

namespace App\Domain\Sri\Exceptions;

use RuntimeException;

/**
 * El XML del SRI no se pudo interpretar.
 *
 * El mensaje nunca incluye el contenido del documento (puede contener datos
 * personales); solo el motivo y, cuando aplica, el nombre del campo.
 */
final class SriXmlException extends RuntimeException
{
    public static function unreadable(): self
    {
        return new self('El XML del comprobante no es válido.');
    }

    public static function unsupportedDocumentType(string $type): self
    {
        return new self("El tipo de comprobante no está soportado por el parser: {$type}.");
    }

    public static function missingField(string $field): self
    {
        return new self("El XML no contiene el campo obligatorio '{$field}'.");
    }

    public static function invalidField(string $field): self
    {
        return new self("El campo '{$field}' del XML no tiene un valor válido.");
    }

    public static function tooLarge(int $bytes, int $limit): self
    {
        return new self("El XML supera el tamaño máximo permitido ({$bytes} > {$limit} bytes).");
    }
}
