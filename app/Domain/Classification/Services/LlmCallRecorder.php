<?php

declare(strict_types=1);

namespace App\Domain\Classification\Services;

use App\Domain\Classification\Data\LlmCallRecord;
use App\Domain\Classification\Models\LlmCall;

/**
 * Persiste la bitácora de llamadas al clasificador.
 *
 * Es el único punto que escribe en `llm_calls`; el resto de servicios recibe
 * el modelo ya persistido. Nunca guarda la descripción del ítem, solo el hash.
 */
final readonly class LlmCallRecorder
{
    public const PURPOSE_CLASSIFY_ITEM = 'classify_item';

    public function record(LlmCallRecord $record): LlmCall
    {
        return LlmCall::query()->create([
            'tenant_id' => $record->tenantId,
            'company_id' => $record->companyId,
            'purpose' => $record->purpose,
            'provider' => $record->provider,
            'model' => $record->model,
            'prompt_version' => $record->promptVersion,
            'input_hash' => $record->inputHash,
            'input_tokens' => $record->inputTokens,
            'output_tokens' => $record->outputTokens,
            'cost_micros' => $record->costMicros,
            'latency_ms' => $record->latencyMs,
            'status' => $record->status,
            'error_code' => $record->errorCode,
        ]);
    }
}
