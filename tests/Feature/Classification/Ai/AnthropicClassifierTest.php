<?php

declare(strict_types=1);

use App\Domain\Classification\Data\ClassificationCategory;
use App\Domain\Classification\Data\ClassificationRequest;
use App\Domain\Classification\Exceptions\ClassifierUnavailable;
use App\Domain\Classification\Exceptions\InvalidClassifierOutput;
use App\Domain\Classification\Services\ClassifierOutputValidator;
use App\Domain\Classification\Services\ClassifierPrompt;
use App\Integrations\Ai\AnthropicClassifier;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

function anthropicRequest(): ClassificationRequest
{
    return new ClassificationRequest(
        description: 'PAPELERIA Y SUMINISTROS',
        categories: [
            new ClassificationCategory(1, 'operativo', 'Gastos operativos'),
            new ClassificationCategory(2, 'alimentacion', 'Alimentación'),
        ],
    );
}

function anthropicClassifier(string $apiKey = 'test-key'): AnthropicClassifier
{
    return new AnthropicClassifier(
        new ClassifierPrompt,
        new ClassifierOutputValidator,
        'claude-sonnet-4-5',
        $apiKey,
        'https://api.anthropic.com/v1',
        '2023-06-01',
        512,
        5,
        2,
    );
}

it('clasifica con Anthropic y devuelve metadatos de la llamada', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => '{"category":"operativo","confidence":0.88,"reason":"Suministros de oficina"}'],
            ],
            'usage' => ['input_tokens' => 200, 'output_tokens' => 30],
        ]),
    ]);

    $result = anthropicClassifier()->classify(anthropicRequest());

    expect($result->suggestion?->categoryCode)->toBe('operativo')
        ->and($result->suggestion?->confidence)->toBe(0.88)
        ->and($result->model)->toBe('claude-sonnet-4-5')
        ->and($result->inputTokens)->toBe(200)
        ->and($result->outputTokens)->toBe(30);

    Http::assertSent(function (Request $request): bool {
        $data = $request->data();

        return $request->url() === 'https://api.anthropic.com/v1/messages'
            && $request->hasHeader('x-api-key', 'test-key')
            && $request->hasHeader('anthropic-version', '2023-06-01')
            && $data['model'] === 'claude-sonnet-4-5'
            && $data['max_tokens'] === 512
            && $data['temperature'] === 0
            && ! str_contains($request->body(), 'issuer_ruc');
    });
});

it('traduce un 429 de Anthropic a proveedor no disponible', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response(['type' => 'error'], 429),
    ]);

    expect(fn () => anthropicClassifier()->classify(anthropicRequest()))
        ->toThrow(ClassifierUnavailable::class, 'limitó la tasa');
});

it('rechaza contenido inválido de Anthropic', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'content' => [
                ['type' => 'text', 'text' => '{"category":"desconocida","confidence":0.9,"reason":"x"}'],
            ],
        ]),
    ]);

    expect(fn () => anthropicClassifier()->classify(anthropicRequest()))
        ->toThrow(InvalidClassifierOutput::class);
});

it('falla antes de la red cuando no hay clave de Anthropic', function (): void {
    Http::fake();

    expect(fn () => anthropicClassifier('')->classify(anthropicRequest()))
        ->toThrow(ClassifierUnavailable::class, 'anthropic');

    Http::assertNothingSent();
});
