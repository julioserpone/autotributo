<?php

declare(strict_types=1);

namespace App\Domain\Classification\Exceptions;

use RuntimeException;

/**
 * La salida del LLM no cumple el contrato estricto.
 *
 * Se usa para distinguir un problema de forma (recuperable con un reintento)
 * de una caída del proveedor (timeout, 429, 5xx). El mensaje no replica la
 * salida cruda para no filtrar contenido del comprobante a los logs.
 */
final class InvalidClassifierOutput extends RuntimeException
{
    private function __construct(string $message, private readonly string $errorCode)
    {
        parent::__construct($message);
    }

    public static function malformedJson(): self
    {
        return new self('La salida del clasificador no es JSON válido.', 'invalid_json');
    }

    public static function unexpectedShape(): self
    {
        return new self(
            'La salida del clasificador no tiene exactamente category, confidence y reason.',
            'unexpected_shape',
        );
    }

    public static function unknownCategory(string $category): self
    {
        return new self(
            "La categoría sugerida no pertenece al catálogo: {$category}.",
            'unknown_category',
        );
    }

    public static function confidenceOutOfRange(): self
    {
        return new self(
            'La confianza sugerida debe ser un número entre 0 y 1.',
            'confidence_out_of_range',
        );
    }

    public static function invalidReason(): self
    {
        return new self(
            'El motivo de la sugerencia debe ser un texto breve no vacío.',
            'invalid_reason',
        );
    }

    public static function malformedProviderResponse(): self
    {
        return new self(
            'La respuesta del proveedor de IA no contiene el texto esperado.',
            'malformed_provider_response',
        );
    }

    public function errorCode(): string
    {
        return $this->errorCode;
    }
}
