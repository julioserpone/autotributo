<?php

declare(strict_types=1);

use App\Domain\Classification\Services\LlmCostCalculator;

it('calcula el coste en micros con enteros', function (): void {
    $calculator = new LlmCostCalculator([
        'gpt-4.1-mini' => ['input' => 400_000, 'output' => 1_600_000],
    ]);

    expect($calculator->costMicros('gpt-4.1-mini', 1_000, 500))->toBe(1_200)
        ->and($calculator->costMicros('gpt-4.1-mini', 1_000_000, 0))->toBe(400_000)
        ->and($calculator->costMicros('gpt-4.1-mini', 0, 1_000_000))->toBe(1_600_000);
});

it('trunca fracciones de micro y nunca devuelve negativos', function (): void {
    $calculator = new LlmCostCalculator([
        'gpt-4.1-mini' => ['input' => 400_000, 'output' => 1_600_000],
    ]);

    expect($calculator->costMicros('gpt-4.1-mini', 1, 0))->toBe(0)
        ->and($calculator->costMicros('gpt-4.1-mini', -10, -10))->toBe(0);
});

it('registra coste cero para un modelo sin precio configurado', function (): void {
    $calculator = new LlmCostCalculator([
        'gpt-4.1-mini' => ['input' => 400_000, 'output' => 1_600_000],
    ]);

    expect($calculator->isPriced('gpt-4.1-mini'))->toBeTrue()
        ->and($calculator->isPriced('modelo-desconocido'))->toBeFalse()
        ->and($calculator->costMicros('modelo-desconocido', 10_000, 10_000))->toBe(0);
});
