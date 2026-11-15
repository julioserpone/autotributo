<?php

declare(strict_types=1);

use App\Domain\Shared\Values\Money;
use Brick\Math\BigDecimal;

it('crea importes desde centavos sin perder precisión', function (): void {
    $money = Money::cents(123_456);

    expect($money->toCents())->toBe(123_456)
        ->and($money->decimal())->toBe('1234.56')
        ->and((string) $money)->toBe('1234.56');
});

it('representa el cero y los negativos', function (): void {
    expect(Money::zero()->isZero())->toBeTrue()
        ->and(Money::zero()->toCents())->toBe(0)
        ->and(Money::cents(-1)->isNegative())->toBeTrue()
        ->and(Money::cents(1)->isNegative())->toBeFalse();
});

it('redondea HALF_UP al convertir un decimal del borde', function (string $amount, int $cents): void {
    expect(Money::ofDecimal($amount)->toCents())->toBe($cents);
})->with([
    'sin decimales' => ['1200', 120_000],
    'dos decimales exactos' => ['1234.56', 123_456],
    'tercer decimal a la baja' => ['10.004', 1_000],
    'tercer decimal al alza' => ['10.005', 1_001],
    'medio centavo' => ['0.015', 2],
    'seis decimales' => ['0.005001', 1],
    'negativo al alza' => ['-10.005', -1_001],
]);

it('rechaza un importe que no es numérico', function (): void {
    Money::ofDecimal('diez dólares');
})->throws(InvalidArgumentException::class, 'Importe inválido: diez dólares');

it('suma y resta sin redondear', function (): void {
    $result = Money::cents(1_000)->plus(Money::cents(234))->minus(Money::cents(1_134));

    expect($result->toCents())->toBe(100)
        ->and($result->decimal())->toBe('1.00');
});

it('multiplica por un factor decimal con redondeo HALF_UP a centavos', function (): void {
    $amount = Money::cents(10_000);

    expect($amount->multipliedBy('0.25')->toCents())->toBe(2_500)
        ->and($amount->multipliedBy('0.166667')->toCents())->toBe(1_667)
        ->and($amount->multipliedBy(BigDecimal::of('0.666667'))->toCents())->toBe(6_667)
        ->and($amount->multipliedBy('0')->isZero())->toBeTrue();
});

it('compara importes por valor', function (): void {
    expect(Money::cents(500)->equals(Money::cents(500)))->toBeTrue()
        ->and(Money::cents(500)->equals(Money::cents(501)))->toBeFalse();
});
