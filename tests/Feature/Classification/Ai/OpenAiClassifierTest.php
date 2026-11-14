<?php

declare(strict_types=1);

use App\Domain\Classification\Data\ClassificationCategory;
use App\Domain\Classification\Data\ClassificationRequest;
use App\Domain\Classification\Exceptions\ClassifierUnavailable;
use App\Domain\Classification\Exceptions\InvalidClassifierOutput;
use App\Domain\Classification\Services\ClassifierOutputValidator;
use App\Domain\Classification\Services\ClassifierPrompt;
use App\Integrations\Ai\OpenAiClassifier;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function openAiRequest(): ClassificationRequest
{
    return new ClassificationRequest(
        description: 'SERVICIOS DE CONSULTORIA CONTABLE',
        categories: [
            new ClassificationCategory(1, 'operativo', 'Gastos operativos'),
            new ClassificationCategory(2, 'alimentacion', 'Alimentación'),
        ],
    );
}

function openAiClassifier(string $apiKey = 'test-key'): OpenAiClassifier
{
    return new OpenAiClassifier(
        new ClassifierPrompt,
        new ClassifierOutputValidator,
        'gpt-4.1-mini',
        $apiKey,
        'https://api.openai.com/v1',
        5,
        2,
    );
}

function openAiContent(string $json): array
{
    return [
        'choices' => [
            ['message' => ['role' => 'assistant', 'content' => $json]],
        ],
        'usage' => ['prompt_tokens' => 123, 'completion_tokens' => 45],
    ];
}

it('clasifica con OpenAI y devuelve metadatos de la llamada', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(openAiContent(
            '{"category":"operativo","confidence":0.93,"reason":"Servicios profesionales"}',
        )),
    ]);

    $result = openAiClassifier()->classify(openAiRequest());

    expect($result->suggestion?->categoryCode)->toBe('operativo')
        ->and($result->suggestion?->confidence)->toBe(0.93)
        ->and($result->model)->toBe('gpt-4.1-mini')
        ->and($result->promptVersion)->toBe('v1')
        ->and($result->inputTokens)->toBe(123)
        ->and($result->outputTokens)->toBe(45)
        ->and($result->latencyMs)->toBeGreaterThanOrEqual(0);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.openai.com/v1/chat/completions'
            && $request->hasHeader('Authorization', 'Bearer test-key')
            && $data['model'] === 'gpt-4.1-mini'
            && $data['temperature'] === 0
            && $data['response_format']['type'] === 'json_schema'
            && $data['response_format']['json_schema']['strict'] === true
            && $data['response_format']['json_schema']['schema']['properties']['category']['enum'] === ['operativo', 'alimentacion']
            && ! str_contains($request->body(), 'issuer_ruc');
    });
});

it('traduce un 429 a proveedor no disponible', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => ['message' => 'rate limited']], 429),
    ]);

    expect(fn () => openAiClassifier()->classify(openAiRequest()))
        ->toThrow(ClassifierUnavailable::class, 'limitó la tasa');
});

it('traduce un 5xx a proveedor no disponible', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(['error' => 'boom'], 503),
    ]);

    expect(fn () => openAiClassifier()->classify(openAiRequest()))
        ->toThrow(ClassifierUnavailable::class, 'HTTP 503');
});

it('traduce un timeout de conexión a proveedor no disponible', function (): void {
    Http::fake(static function (): never {
        throw new ConnectionException('Connection timed out');
    });

    expect(fn () => openAiClassifier()->classify(openAiRequest()))
        ->toThrow(ClassifierUnavailable::class, 'conectar');
});

it('rechaza una respuesta sin contenido o con JSON inválido', function (): void {
    Http::fake([
        'api.openai.com/*' => Http::response(openAiContent('no es json')),
    ]);

    expect(fn () => openAiClassifier()->classify(openAiRequest()))
        ->toThrow(InvalidClassifierOutput::class);

    Http::fake([
        'api.openai.com/*' => Http::response(['choices' => []]),
    ]);

    expect(fn () => openAiClassifier()->classify(openAiRequest()))
        ->toThrow(InvalidClassifierOutput::class);
});

it('falla antes de la red cuando no hay clave de OpenAI', function (): void {
    Http::fake();

    expect(fn () => openAiClassifier('')->classify(openAiRequest()))
        ->toThrow(ClassifierUnavailable::class, 'openai');

    Http::assertNothingSent();
});
