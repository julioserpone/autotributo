<?php

declare(strict_types=1);

namespace App\Domain\Classification\Data;

use App\Domain\Classification\Enums\LlmCallStatus;

/**
 * Datos de una fila de `llm_calls`.
 *
 * Se usa un DTO en lugar de una lista larga de argumentos para que el registro
 * de éxitos, fallos y cortes por presupuesto comparta la misma forma.
 */
final readonly class LlmCallRecord
{
    public function __construct(
        public int $tenantId,
        public string $purpose,
        public string $inputHash,
        public string $provider,
        public string $model,
        public ?string $promptVersion,
        public LlmCallStatus $status,
        public int $inputTokens = 0,
        public int $outputTokens = 0,
        public int $costMicros = 0,
        public int $latencyMs = 0,
        public ?string $errorCode = null,
        public ?int $companyId = null,
    ) {}
}
