<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

/**
 * Resultado de una llamada al clasificador.
 *
 * `suggestion` es nulo cuando el proveedor (por ejemplo el fake por defecto)
 * no propone nada y el ítem debe seguir pendiente. `provider`, `cached` y
 * `llmCallId` los completa el gobierno de IA (F3-04): la caché reutiliza una
 * sugerencia anterior y `llmCallId` enlaza la clasificación con `llm_calls`.
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
        public string $provider = 'unknown',
        public bool $cached = false,
        public ?int $llmCallId = null,
    ) {}

    public static function withoutSuggestion(
        string $model,
        string $promptVersion,
        string $provider = 'unknown',
    ): self {
        return new self(
            suggestion: null,
            model: $model,
            promptVersion: $promptVersion,
            provider: $provider,
        );
    }

    public function withGovernance(?int $llmCallId, bool $cached): self
    {
        return new self(
            suggestion: $this->suggestion,
            model: $this->model,
            promptVersion: $this->promptVersion,
            inputTokens: $cached ? 0 : $this->inputTokens,
            outputTokens: $cached ? 0 : $this->outputTokens,
            latencyMs: $cached ? 0 : $this->latencyMs,
            provider: $this->provider,
            cached: $cached,
            llmCallId: $llmCallId,
        );
    }
}
