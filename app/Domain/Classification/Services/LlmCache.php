<?php

declare(strict_types=1);

namespace App\Domain\Classification\Services;

use App\Domain\Classification\Data\ClassificationSuggestion;
use App\Domain\Classification\Data\ClassifierResult;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Enums\LlmCallStatus;
use App\Domain\Classification\Models\Classification;
use App\Domain\Classification\Models\ExpenseCategory;
use App\Domain\Classification\Models\LlmCall;

/**
 * Reutiliza clasificaciones previas con la misma huella de entrada.
 *
 * La caché vive en la base de datos: se busca la última llamada exitosa con el
 * mismo `input_hash` dentro de la retención y se recupera la clasificación
 * enlazada. No se guarda la descripción del ítem, solo la huella.
 *
 * Una clasificación rechazada por un humano no se reutiliza; las sugeridas,
 * aceptadas o pendientes de revisión sí, porque siguen representando la salida
 * del modelo para esa entrada.
 */
final readonly class LlmCache
{
    private const HIT_REASON = 'Sugerencia reutilizada de una clasificación previa (caché).';

    public function __construct(
        private bool $enabled = true,
        private int $ttlDays = 30,
    ) {}

    public function find(int $tenantId, string $inputHash): ?ClassifierResult
    {
        if (! $this->enabled) {
            return null;
        }

        $call = LlmCall::query()
            ->where('tenant_id', $tenantId)
            ->where('input_hash', $inputHash)
            ->where('status', LlmCallStatus::Success->value)
            ->where('created_at', '>=', now()->subDays(max(1, $this->ttlDays)))
            ->latest('id')
            ->first();

        if (! $call instanceof LlmCall) {
            return null;
        }

        $classification = Classification::query()
            ->where('llm_call_id', $call->id)
            ->where('status', '!=', ClassificationStatus::Rejected->value)
            ->with('expenseCategory')
            ->first();

        $category = $classification instanceof Classification ? $classification->expenseCategory : null;

        if (! $classification instanceof Classification || ! $category instanceof ExpenseCategory) {
            return null;
        }

        return new ClassifierResult(
            suggestion: new ClassificationSuggestion(
                categoryCode: (string) $category->code,
                confidence: (float) $classification->confidence,
                reason: self::HIT_REASON,
            ),
            model: (string) $call->model,
            promptVersion: $call->prompt_version ?? ClassifierPrompt::VERSION,
            provider: (string) $call->provider,
            cached: true,
            llmCallId: (int) $call->id,
        );
    }
}
