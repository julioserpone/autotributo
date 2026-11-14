<?php

declare(strict_types=1);

namespace App\Domain\Classification\Services;

use App\Domain\Classification\Data\ClassificationRequest;
use App\Domain\Classification\Data\ClassificationSuggestion;
use App\Domain\Classification\Exceptions\InvalidClassifierOutput;
use JsonException;

/**
 * Valida la salida cruda del LLM contra un contrato estricto.
 *
 * No se aceptan bloques markdown, claves adicionales, categorías fuera del
 * catálogo ni confianzas fuera de 0..1. Un fallo aquí es recuperable con un
 * reintento (lo decide `ResilientClassifier`), no una caída del proveedor.
 */
final class ClassifierOutputValidator
{
    private const MAX_REASON_LENGTH = 300;

    /** @var list<string> */
    private const EXPECTED_KEYS = ['category', 'confidence', 'reason'];

    public function validate(string $raw, ClassificationRequest $request): ClassificationSuggestion
    {
        try {
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            throw InvalidClassifierOutput::malformedJson();
        }

        if (! is_array($decoded) || $decoded === [] || array_is_list($decoded)) {
            throw InvalidClassifierOutput::unexpectedShape();
        }

        $keys = array_keys($decoded);
        sort($keys);
        $expected = self::EXPECTED_KEYS;
        sort($expected);

        if ($keys !== $expected) {
            throw InvalidClassifierOutput::unexpectedShape();
        }

        $category = $decoded['category'] ?? null;

        if (! is_string($category) || ! in_array($category, $request->categoryCodes(), true)) {
            throw InvalidClassifierOutput::unknownCategory(is_string($category) ? $category : 'null');
        }

        $confidence = $decoded['confidence'] ?? null;

        if (! is_int($confidence) && ! is_float($confidence)) {
            throw InvalidClassifierOutput::confidenceOutOfRange();
        }

        $confidence = (float) $confidence;

        if (! is_finite($confidence) || $confidence < 0.0 || $confidence > 1.0) {
            throw InvalidClassifierOutput::confidenceOutOfRange();
        }

        $reason = $decoded['reason'] ?? null;

        if (! is_string($reason)) {
            throw InvalidClassifierOutput::invalidReason();
        }

        $reason = trim($reason);

        if ($reason === '' || mb_strlen($reason) > self::MAX_REASON_LENGTH) {
            throw InvalidClassifierOutput::invalidReason();
        }

        return new ClassificationSuggestion($category, $confidence, $reason);
    }

    /**
     * JSON Schema que se envía a OpenAI en modo estricto.
     *
     * Se limita a las palabras clave ampliamente soportadas (tipo, enum,
     * propiedades requeridas y `additionalProperties=false`). Los rangos de
     * `confidence` y el largo de `reason` se validan después en PHP: así el
     * esquema no depende de palabras clave que algunas versiones del
     * proveedor ignoran o rechazan.
     *
     * @param  list<string>  $categoryCodes
     * @return array<string, mixed>
     */
    public static function jsonSchema(array $categoryCodes): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => self::EXPECTED_KEYS,
            'properties' => [
                'category' => [
                    'type' => 'string',
                    'enum' => array_values($categoryCodes),
                ],
                'confidence' => [
                    'type' => 'number',
                ],
                'reason' => [
                    'type' => 'string',
                ],
            ],
        ];
    }
}
