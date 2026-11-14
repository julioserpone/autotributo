<?php

declare(strict_types=1);

use App\Domain\Classification\Data\ClassificationCategory;
use App\Domain\Classification\Data\ClassificationRequest;
use App\Domain\Classification\Exceptions\ClassifierUnavailable;
use App\Domain\Classification\Exceptions\InvalidClassifierOutput;
use App\Domain\Classification\Services\ClassifierCircuitBreaker;
use App\Domain\Classification\Services\ResilientClassifier;
use App\Domain\Tenancy\TenantContext;
use App\Integrations\Ai\FakeClassifier;
use Illuminate\Support\Facades\Cache;

function resilientRequest(): ClassificationRequest
{
    return new ClassificationRequest(
        description: 'SERVICIO PROFESIONAL',
        categories: [new ClassificationCategory(1, 'operativo', 'Gastos operativos')],
    );
}

function resilientClassifier(FakeClassifier $fake, int $maxAttempts = 2, int $threshold = 5): ResilientClassifier
{
    return new ResilientClassifier(
        $fake,
        new ClassifierCircuitBreaker($threshold, 60),
        $maxAttempts,
        app(TenantContext::class),
    );
}

beforeEach(function (): void {
    Cache::flush();
});

it('reintenta una salida inválida y acepta la segunda respuesta', function (): void {
    $fake = new FakeClassifier;
    $fake->pushException(InvalidClassifierOutput::malformedJson())
        ->pushSuggestion('operativo', 0.95);

    $result = resilientClassifier($fake)->classify(resilientRequest());

    expect($result->suggestion?->categoryCode)->toBe('operativo')
        ->and($fake->requests)->toHaveCount(2);
});

it('propaga la salida inválida tras agotar los intentos', function (): void {
    $fake = new FakeClassifier;
    $fake->pushException(InvalidClassifierOutput::malformedJson())
        ->pushException(InvalidClassifierOutput::unexpectedShape());

    expect(fn () => resilientClassifier($fake)->classify(resilientRequest()))
        ->toThrow(InvalidClassifierOutput::class);

    expect($fake->requests)->toHaveCount(2);
});

it('no reintenta una caída del proveedor', function (): void {
    $fake = new FakeClassifier;
    $fake->pushException(ClassifierUnavailable::rateLimited())
        ->pushSuggestion('operativo', 0.95);

    expect(fn () => resilientClassifier($fake)->classify(resilientRequest()))
        ->toThrow(ClassifierUnavailable::class);

    expect($fake->requests)->toHaveCount(1);
});

it('corta las llamadas cuando el circuito está abierto', function (): void {
    $fake = new FakeClassifier;
    $fake->pushException(ClassifierUnavailable::rateLimited())
        ->pushSuggestion('operativo', 0.95);

    $classifier = resilientClassifier($fake, threshold: 1);

    expect(fn () => $classifier->classify(resilientRequest()))
        ->toThrow(ClassifierUnavailable::class);

    expect(fn () => $classifier->classify(resilientRequest()))
        ->toThrow(ClassifierUnavailable::class);

    expect($fake->requests)->toHaveCount(1);
});
