<?php

declare(strict_types=1);

use App\Domain\Fiscal\Services\Proportionality;
use App\Domain\Shared\Values\Money;
use Brick\Math\BigDecimal;

it('calcula la porción de ventas con tarifa 0 % con 6 decimales', function (
    int $salesAtZeroCents,
    int $salesAtGeneralCents,
    string $expected,
): void {
    $factor = Proportionality::factor(
        Money::cents($salesAtZeroCents),
        Money::cents($salesAtGeneralCents),
    );

    expect($factor->toScale(Proportionality::SCALE)->__toString())->toBe($expected)
        ->and($factor->getScale())->toBe(Proportionality::SCALE);
})->with([
    'solo ventas gravadas' => [0, 100_00, '0.000000'],
    'solo ventas 0 %' => [100_00, 0, '1.000000'],
    'caso base 25 %' => [100_00, 300_00, '0.250000'],
    'mitad y mitad' => [50_00, 50_00, '0.500000'],
    'sin ventas en el período' => [0, 0, '0.000000'],
    'diez por ciento' => [100_00, 900_00, '0.100000'],
    'un tercio redondea a la baja' => [1, 2, '0.333333'],
    'dos tercios redondean al alza' => [2, 1, '0.666667'],
    'séptimo decimal cinco redondea al alza' => [1, 127, '0.007813'],
    'casi todo 0 %' => [999_999_00, 1_00, '0.999999'],
    'proporción mínima representable' => [1, 1_999_999, '0.000001'],
]);

it('calcula la cara complementaria para que ambas sumen exactamente uno', function (
    int $salesAtZeroCents,
    int $salesAtGeneralCents,
): void {
    $factor = Proportionality::factor(
        Money::cents($salesAtZeroCents),
        Money::cents($salesAtGeneralCents),
    );
    $complement = Proportionality::complement($factor);

    expect($factor->plus($complement)->__toString())->toBe('1.000000')
        ->and($complement->getScale())->toBe(Proportionality::SCALE);
})->with([
    'caso base' => [100_00, 300_00],
    'un tercio' => [1, 2],
    'dos tercios' => [2, 1],
    'sin ventas' => [0, 0],
    'solo gravadas' => [0, 500_00],
]);

it('aplica el factor a un importe con redondeo HALF_UP a centavos', function (): void {
    $credit = Money::cents(10_000);

    expect(Proportionality::apply($credit, BigDecimal::of('0.250000'))->toCents())->toBe(2_500)
        ->and(Proportionality::apply(Money::cents(1_000), BigDecimal::of('0.333333'))->toCents())->toBe(333)
        ->and(Proportionality::apply(Money::cents(1_000), BigDecimal::of('0.666667'))->toCents())->toBe(667)
        ->and(Proportionality::apply($credit, BigDecimal::zero())->isZero())->toBeTrue();
});

it('rechaza ventas negativas', function (): void {
    Proportionality::factor(Money::cents(-1), Money::cents(100));
})->throws(InvalidArgumentException::class, 'no pueden ser negativas');

it('rechaza un factor fuera del rango 0..1', function (string $factor): void {
    Proportionality::complement(BigDecimal::of($factor));
})->with([
    'mayor que uno' => '1.000001',
    'negativo' => '-0.000001',
])->throws(InvalidArgumentException::class, 'El factor debe estar entre 0 y 1');
