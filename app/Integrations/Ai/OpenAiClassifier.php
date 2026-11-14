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
 * Adaptador del clasificador para la API de OpenAI.
 *
 * Usa `response_format` con JSON Schema estricto y temperatura 0. Solo traduce
 * HTTP: la validación final la hace `ClassifierOutputValidator`, de modo que
 * una respuesta que se salga del esquema se trata igual en todos los
 * proveedores.
 */
final readonly class OpenAiClassifier implements Classifier
{
    public function __construct(
        private ClassifierPrompt $prompt,
        private ClassifierOutputValidator $validator,
        private string $model,
        private string $apiKey,
        private string $baseUrl,
        private float $timeout,
        private float $connectTimeout,
    ) {}

    public function classify(ClassificationRequest $request): ClassifierResult
    {
        if (trim($this->apiKey) === '') {
            throw ClassifierUnavailable::missingApiKey('openai');
        }

        $started = hrtime(true);

        try {
            $response = Http::withToken($this->apiKey)
                ->acceptJson()
                ->asJson()
                ->timeout($this->timeout)
                ->connectTimeout($this->connectTimeout)
                ->post($this->endpoint(), [
                    'model' => $this->model,
                    'temperature' => 0,
                    'messages' => [
                        ['role' => 'system', 'content' => $this->prompt->system($request)],
                        ['role' => 'user', 'content' => $this->prompt->user($request)],
                    ],
                    'response_format' => [
                        'type' => 'json_schema',
                        'json_schema' => [
                            'name' => 'invoice_item_classification',
                            'strict' => true,
                            'schema' => ClassifierOutputValidator::jsonSchema($request->categoryCodes()),
                        ],
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

        $content = data_get($body, 'choices.0.message.content');

        if (! is_string($content)) {
            throw InvalidClassifierOutput::malformedProviderResponse();
        }

        return new ClassifierResult(
            suggestion: $this->validator->validate($content, $request),
            model: $this->model,
            promptVersion: ClassifierPrompt::VERSION,
            inputTokens: (int) data_get($body, 'usage.prompt_tokens', 0),
            outputTokens: (int) data_get($body, 'usage.completion_tokens', 0),
            latencyMs: intdiv(hrtime(true) - $started, 1_000_000),
        );
    }

    private function endpoint(): string
    {
        return rtrim($this->baseUrl, '/').'/chat/completions';
    }
}
