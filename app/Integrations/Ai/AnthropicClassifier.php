<?php

declare(strict_types=1);

namespace App\Integrations\Ai;

use App\Domain\Classification\Contracts\Classifier;
use App\Domain\Classification\Data\ClassificationRequest;
use App\Domain\Classification\Data\ClassifierResult;
use App\Domain\Classification\Exceptions\ClassifierUnavailable;
use App\Domain\Classification\Exceptions\InvalidClassifierOutput;
use App\Domain\Classification\Services\ClassifierOutputValidator;
use App\Domain\Classification\Services\ClassifierPrompt;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;

/**
 * Adaptador del clasificador para la API de Anthropic.
 *
 * Anthropic no ofrece JSON Schema estricto en este endpoint, así que el
 * contrato se exige en el prompt y se valida después con el mismo validador
 * que OpenAI. `system` lleva las categorías y la descripción viaja como
 * mensaje de usuario con datos no confiables.
 */
final readonly class AnthropicClassifier implements Classifier
{
    public function __construct(
        private ClassifierPrompt $prompt,
        private ClassifierOutputValidator $validator,
        private string $model,
        private string $apiKey,
        private string $baseUrl,
        private string $version,
        private int $maxOutputTokens,
        private float $timeout,
        private float $connectTimeout,
    ) {}

    public function classify(ClassificationRequest $request): ClassifierResult
    {
        if (trim($this->apiKey) === '') {
            throw ClassifierUnavailable::missingApiKey('anthropic');
        }

        $started = hrtime(true);

        try {
            $response = Http::withHeaders([
                'x-api-key' => $this->apiKey,
                'anthropic-version' => $this->version,
            ])
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->post($this->endpoint(), [
                    'model' => $this->model,
                    'max_tokens' => $this->maxOutputTokens,
                    'temperature' => 0,
                    'system' => $this->prompt->system($request),
                    'messages' => [
                        ['role' => 'user', 'content' => $this->prompt->user($request)],
                    ],
                ]);
        } catch (ConnectionException $exception) {
            throw ClassifierUnavailable::connectionFailed($exception);
        }

        if ($response->status() === 429) {
            throw ClassifierUnavailable::rateLimited();
        }

        if (! $response->successful()) {
            throw ClassifierUnavailable::requestFailed($response->status());
        }

        $body = $response->json();

        if (! is_array($body)) {
            throw InvalidClassifierOutput::malformedProviderResponse();
        }

        $content = data_get($body, 'content.0.text');

        if (! is_string($content)) {
            throw InvalidClassifierOutput::malformedProviderResponse();
        }

        return new ClassifierResult(
            suggestion: $this->validator->validate($content, $request),
            model: $this->model,
            promptVersion: ClassifierPrompt::VERSION,
            inputTokens: (int) data_get($body, 'usage.input_tokens', 0),
            outputTokens: (int) data_get($body, 'usage.output_tokens', 0),
            latencyMs: intdiv(hrtime(true) - $started, 1_000_000),
            provider: 'anthropic',
        );
    }

    private function endpoint(): string
    {
        return rtrim($this->baseUrl, '/').'/messages';
    }
}
