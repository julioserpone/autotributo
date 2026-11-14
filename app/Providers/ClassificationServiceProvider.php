<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Classification\Actions\ClassifyInvoiceItems;
use App\Domain\Classification\Contracts\Classifier;
use App\Domain\Classification\Services\ClassificationRuleMatcher;
use App\Domain\Classification\Services\ClassificationRuleRepository;
use App\Domain\Classification\Services\ClassifierCircuitBreaker;
use App\Domain\Classification\Services\ClassifierOutputValidator;
use App\Domain\Classification\Services\ClassifierPrompt;
use App\Domain\Classification\Services\GovernedClassifier;
use App\Domain\Classification\Services\LlmBudget;
use App\Domain\Classification\Services\LlmCache;
use App\Domain\Classification\Services\LlmCallRecorder;
use App\Domain\Classification\Services\LlmCostCalculator;
use App\Domain\Classification\Services\LlmInputHash;
use App\Domain\Classification\Services\ResilientClassifier;
use App\Domain\Tenancy\TenantContext;
use App\Integrations\Ai\AnthropicClassifier;
use App\Integrations\Ai\FakeClassifier;
use App\Integrations\Ai\OpenAiClassifier;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;
use InvalidArgumentException;

/**
 * Registra el clasificador de IA y su integración con la clasificación de
 * ítems. El proveedor concreto se elige por configuración; `fake` es el valor
 * por defecto para desarrollo y pruebas.
 */
final class ClassificationServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(FakeClassifier::class);

        $this->app->singleton(Classifier::class, function (Application $app): Classifier {
            $driver = (string) config('ai.classifier.driver', 'fake');
            $model = match ($driver) {
                'anthropic' => (string) config('ai.anthropic.model', 'claude-sonnet-4-5'),
                default => (string) config('ai.classifier.model', 'gpt-4.1-mini'),
            };

            $inner = match ($driver) {
                'fake' => $app->make(FakeClassifier::class),
                'openai' => new OpenAiClassifier(
                    $app->make(ClassifierPrompt::class),
                    $app->make(ClassifierOutputValidator::class),
                    (string) config('ai.classifier.model', 'gpt-4.1-mini'),
                    (string) config('ai.openai.api_key', ''),
                    (string) config('ai.openai.base_url', 'https://api.openai.com/v1'),
                    (float) config('ai.classifier.timeout', 20),
                    (float) config('ai.classifier.connect_timeout', 5),
                ),
                'anthropic' => new AnthropicClassifier(
                    $app->make(ClassifierPrompt::class),
                    $app->make(ClassifierOutputValidator::class),
                    (string) config('ai.anthropic.model', 'claude-sonnet-4-5'),
                    (string) config('ai.anthropic.api_key', ''),
                    (string) config('ai.anthropic.base_url', 'https://api.anthropic.com/v1'),
                    (string) config('ai.anthropic.version', '2023-06-01'),
                    (int) config('ai.classifier.max_output_tokens', 512),
                    (float) config('ai.classifier.timeout', 20),
                    (float) config('ai.classifier.connect_timeout', 5),
                ),
                default => throw new InvalidArgumentException("Driver de clasificador IA no soportado: {$driver}."),
            };

            $resilient = new ResilientClassifier(
                $inner,
                new ClassifierCircuitBreaker(
                    (int) config('ai.classifier.circuit.failure_threshold', 5),
                    (int) config('ai.classifier.circuit.cooldown_seconds', 60),
                ),
                (int) config('ai.classifier.max_attempts', 2),
                $app->make(TenantContext::class),
            );

            $monthlyUsd = (float) config('ai.budget.monthly_usd', 0);
            $limitMicros = $monthlyUsd > 0 ? (int) round($monthlyUsd * 1_000_000) : 0;

            /** @var array<string, array{input:int, output:int}> $prices */
            $prices = (array) config('ai.pricing.models', []);
            /** @var array{input:int, output:int} $fallbackPrice */
            $fallbackPrice = (array) config('ai.pricing.default', ['input' => 0, 'output' => 0]);

            return new GovernedClassifier(
                $resilient,
                new LlmCache(
                    (bool) config('ai.cache.enabled', true),
                    (int) config('ai.cache.ttl_days', 30),
                ),
                new LlmBudget($limitMicros, (float) config('ai.budget.warn_ratio', 0.8)),
                new LlmCostCalculator($prices, $fallbackPrice),
                $app->make(LlmInputHash::class),
                $app->make(LlmCallRecorder::class),
                $app->make(TenantContext::class),
                $driver,
                $model,
            );
        });

        $this->app->singleton(ClassifyInvoiceItems::class, function (Application $app): ClassifyInvoiceItems {
            return new ClassifyInvoiceItems(
                $app->make(ClassificationRuleMatcher::class),
                $app->make(ClassificationRuleRepository::class),
                $app->make(Classifier::class),
                (float) config('ai.classifier.confidence_threshold', 0.80),
            );
        });
    }
}
