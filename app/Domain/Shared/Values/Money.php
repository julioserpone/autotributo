<?php

declare(strict_types=1);

namespace App\Domain\Shared\Values;

use Brick\Math\BigDecimal;
use Brick\Math\Exception\NumberFormatException;
use Brick\Math\RoundingMode;
use Brick\Money\Money as BrickMoney;
use InvalidArgumentException;
use Stringable;

/**
 * Importe monetario en dólares estadounidenses (Ecuador está dollarizado).
 *
 * Envuelve `brick/money` para que el dominio no dependa de su API: el único
 * punto de entrada son `cents()` (centavos enteros, ADR-0002) y
 * `ofDecimal()` (entrada del usuario, redondeo HALF_UP en el borde).
 *
 * La regla de redondeo es única en todo el proyecto: **HALF_UP** al convertir
 * a centavos, aplicada una sola vez por operación documentada. Nunca se
 * construye desde `float`.
 *
 * @see docs/adr/0002-money-and-rounding.md
 */
final readonly class Money implements Stringable
{
    /** Moneda fija del MVP: no hay conversión de moneda (ADR-0002). */
    public const CURRENCY = 'USD';

    /** Decimales de la moneda; 1 USD = 100 centavos. */
    public const SCALE = 2;

    private function __construct(
        private BrickMoney $amount,
    ) {}

    /**
     * Importe desde centavos enteros. Es el constructor canónico: todo lo que
     * viene de la base o de un cálculo fiscal llega por aquí.
     */
    public static function cents(int $cents): self
    {
        return new self(BrickMoney::ofMinor($cents, self::CURRENCY));
    }

    /**
     * Importe desde una cadena decimal (`"1234.56"`, `"10.005"`).
     *
     * Pensado para el borde de entrada (FormRequest/DTO): redondea HALF_UP a
     * centavos. Un valor no numérico es un error de captura, no un caso de
     * negocio.
     */
    public static function ofDecimal(string $amount): self
    {
        try {
            $decimal = BigDecimal::of($amount);
        } catch (NumberFormatException) {
            throw new InvalidArgumentException("Importe inválido: {$amount}");
        }

        return new self(BrickMoney::of(
            $decimal->toScale(self::SCALE, RoundingMode::HalfUp),
            self::CURRENCY,
        ));
    }

    public static function zero(): self
    {
        return self::cents(0);
    }

    /**
     * Centavos enteros, el formato en que se persiste y se compara
     * (ADR-0002).
     */
    public function toCents(): int
    {
        return $this->amount->getMinorAmount()->toInt();
    }

    /**
     * Representación decimal con dos dígitos (`"1234.56"`). Es lo que se
     * persiste en un `numeric(18,2)` o se muestra en un reporte.
     */
    public function decimal(): string
    {
        return (string) $this->amount->getAmount()->toScale(self::SCALE);
    }

    public function plus(self $other): self
    {
        return new self($this->amount->plus($other->amount));
    }

    public function minus(self $other): self
    {
        return new self($this->amount->minus($other->amount));
    }

    /**
     * Multiplica por un factor decimal (tasas, proporcionalidad) y redondea a
     * centavos con HALF_UP. El factor se trata como cadena o `BigDecimal`
     * para no introducir `float`.
     */
    public function multipliedBy(BigDecimal|string $factor): self
    {
        return new self($this->amount->multipliedBy($factor, RoundingMode::HalfUp));
    }

    public function isZero(): bool
    {
        return $this->amount->isZero();
    }

    public function isNegative(): bool
    {
        return $this->amount->isNegative();
    }

    public function equals(self $other): bool
    {
        return $this->amount->isEqualTo($other->amount);
    }

    public function __toString(): string
    {
        return $this->decimal();
    }
}
