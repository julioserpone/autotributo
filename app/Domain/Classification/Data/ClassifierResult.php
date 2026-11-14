<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

/**
 * Resultado de una llamada al clasificador.
 *
 * `suggestion` es nulo cuando el proveedor (por ejemplo el fake por defecto)
 * no propone nada y el ítem debe seguir pendiente. Los metadatos de tokens,
 * modelo y latencia quedan disponibles para que F3-04 los registre en
 * `llm_calls`; esta tarea no los persiste.
 */
final readonly class ClassifierResult
{
    public function __construct(
        public ?ClassificationSuggestion $suggestion,
        public string $model,
        public string $promptVersion,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $latencyMs = 0,
    ) {}

    public static function withoutSuggestion(string $model, string $promptVersion): self
    {
        return new self(
            suggestion: null,
            model: $model,
            promptVersion: $promptVersion,
        );
    }
}
