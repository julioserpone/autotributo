<?php

declare(strict_types=1);

namespace App\Domain\Classification\Services;

use App\Domain\Classification\Contracts\Classifier;
use App\Domain\Classification\Data\ClassificationRequest;
use App\Domain\Classification\Data\ClassifierResult;
use App\Domain\Classification\Data\LlmCallRecord;
use App\Domain\Classification\Enums\LlmCallStatus;
use App\Domain\Classification\Exceptions\ClassifierUnavailable;
use App\Domain\Classification\Exceptions\InvalidClassifierOutput;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

/**
 * Gobierno de la IA: caché, presupuesto y bitácora alrededor del clasificador.
 *
 * Orden de decisión: primero la caché (no cuesta), luego el presupuesto y por
 * último el proveedor. Cada intento termina en `llm_calls`, incluido el corte
 * por presupuesto, que no llama al proveedor pero deja el motivo.
 *
 * Un reintento por salida inválida se colapsa en una sola fila: los adaptadores
 * no exponen el consumo de tokens del intento fallido. Es una aproximación
 * conservadora para el presupuesto y suficiente para el MVP.
 */
final readonly class GovernedClassifier implements Classifier
{
    public function __construct(
        private Classifier $inner,
        private LlmCache $cache,
        private LlmBudget $budget,
        private LlmCostCalculator $costs,
        private LlmInputHash $hashes,
        private LlmCallRecorder $recorder,
        private TenantContext $tenants,
        private string $provider,
        private string $model,
    ) {}

    public function classify(ClassificationRequest $request): ClassifierResult
    {
        $tenantId = $this->tenants->id();
        $inputHash = $this->hashes->for($request, $this->model, ClassifierPrompt::VERSION);

        $cached = $this->cache->find($tenantId, $inputHash);

        if ($cached instanceof ClassifierResult) {
            return $cached;
        }

        if ($this->budget->isExhausted($tenantId)) {
            $this->recorder->record(new LlmCallRecord(
                tenantId: $tenantId,
                purpose: LlmCallRecorder::PURPOSE_CLASSIFY_ITEM,
                inputHash: $inputHash,
                provider: $this->provider,
                model: $this->model,
                promptVersion: ClassifierPrompt::VERSION,
                status: LlmCallStatus::BudgetExceeded,
                errorCode: 'budget_exceeded',
            ));

            Log::warning('classification.ai_budget_exceeded', [
                'tenant_id' => $tenantId,
                'spent_micros' => $this->budget->spentMicros($tenantId),
                'limit_micros' => $this->budget->limitMicros(),
            ]);

            throw ClassifierUnavailable::budgetExceeded();
        }

        try {
            $result = $this->inner->classify($request);
        } catch (InvalidClassifierOutput|ClassifierUnavailable $exception) {
            $this->recorder->record(new LlmCallRecord(
                tenantId: $tenantId,
                purpose: LlmCallRecorder::PURPOSE_CLASSIFY_ITEM,
                inputHash: $inputHash,
                provider: $this->provider,
                model: $this->model,
                promptVersion: ClassifierPrompt::VERSION,
                status: $exception instanceof InvalidClassifierOutput
                    ? LlmCallStatus::InvalidOutput
                    : LlmCallStatus::Unavailable,
                errorCode: $exception->errorCode(),
            ));

            throw $exception;
        }

        $provider = $result->provider === 'unknown' ? $this->provider : $result->provider;

        $call = $this->recorder->record(new LlmCallRecord(
            tenantId: $tenantId,
            purpose: LlmCallRecorder::PURPOSE_CLASSIFY_ITEM,
            inputHash: $inputHash,
            provider: $provider,
            model: $result->model,
            promptVersion: $result->promptVersion,
            status: LlmCallStatus::Success,
            inputTokens: $result->inputTokens,
            outputTokens: $result->outputTokens,
            costMicros: $this->costs->costMicros(
                $result->model,
                $result->inputTokens,
                $result->outputTokens,
            ),
            latencyMs: $result->latencyMs,
        ));

        $this->warnBudget($tenantId, $provider, $result->model);

        return $result->withGovernance(llmCallId: (int) $call->id, cached: false);
    }

    private function warnBudget(int $tenantId, string $provider, string $model): void
    {
        if (! $this->budget->isWarningReached($tenantId)) {
            return;
        }

        $key = sprintf('llm-budget-warning:%d:%s', $tenantId, now()->format('Y-m'));

        if (! Cache::add($key, true, now()->endOfMonth())) {
            return;
        }

        Log::warning('classification.ai_budget_warning', [
            'tenant_id' => $tenantId,
            'provider' => $provider,
            'model' => $model,
            'spent_micros' => $this->budget->spentMicros($tenantId),
            'limit_micros' => $this->budget->limitMicros(),
        ]);
    }
}
