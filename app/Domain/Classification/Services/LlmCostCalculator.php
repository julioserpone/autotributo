<?php

declare(strict_types=1);

namespace App\Domain\Classification\Services;

/**
 * Convierte tokens en coste con enteros.
 *
 * Los precios se expresan en **micros de USD por millón de tokens**, de modo
 * que el resultado (`cost_micros`) nunca pasa por `float`. Un modelo sin
 * precio configurado cuesta 0 y el llamador puede detectarlo con
 * `isPriced()` para avisar de la deriva de la tabla de precios.
 */
final readonly class LlmCostCalculator
{
    /**
     * @param  array<string, array{input:int, output:int}>  $models
     * @param  array{input:int, output:int}  $fallback
     */
    public function __construct(
        private array $models,
        private array $fallback = ['input' => 0, 'output' => 0],
    ) {}

    public function isPriced(string $model): bool
    {
        return isset($this->models[$model]);
    }

    public function costMicros(string $model, int $inputTokens, int $outputTokens): int
    {
        $price = $this->models[$model] ?? $this->fallback;

        $micros = max(0, $inputTokens) * $price['input']
            + max(0, $outputTokens) * $price['output'];

        return intdiv($micros, 1_000_000);
    }
}
