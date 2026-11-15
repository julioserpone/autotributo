<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Services;

use App\Domain\Shared\Values\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use InvalidArgumentException;

/**
 * Proporcionalidad de IVA entre ventas con tarifa 0 % y ventas con tarifa
 * general.
 *
 * Cuando un contribuyente vende parte con tarifa 0 % (exportaciones, bienes
 * de tarifa 0 %) y parte con tarifa general, el IVA de las compras comunes no
 * es imputable por completo a una sola actividad: se reparte en la misma
 * proporción que las ventas.
 *
 * Convención de esta clase (la que ya documenta `docs/04-testing-strategy.md`):
 *
 *     factor = ventas_tarifa_0 / (ventas_tarifa_0 + ventas_tarifa_general)
 *
 * El factor se expresa con **6 decimales** y se redondea **HALF_UP**
 * (ADR-0002). Los importes son centavos enteros: no hay `float` en ningún
 * punto del cálculo.
 *
 * El factor por sí solo no decide qué porción del crédito es deducible: cuál
 * de las dos caras aplica al casillero del Formulario 104 depende de la ficha
 * técnica vigente del SRI y debe confirmarlo el contador asesor antes de
 * usarse en una declaración (`docs/fiscal-signoff.md`).
 *
 * @see docs/01-architecture.md § Proporcionalidad de IVA
 */
final class Proportionality
{
    /** Escala del factor, fijada por la arquitectura. */
    public const SCALE = 6;

    private function __construct() {}

    /**
     * Porción de ventas con tarifa 0 % sobre el total de ventas del período.
     *
     * Sin ventas en el período el factor es `0.000000` (no hay crédito que
     * repartir); no se lanza excepción porque un período en cero es un caso
     * de negocio válido.
     */
    public static function factor(Money $salesAtZero, Money $salesAtGeneral): BigDecimal
    {
        self::assertNotNegative($salesAtZero, 'ventas con tarifa 0 %');
        self::assertNotNegative($salesAtGeneral, 'ventas con tarifa general');

        $zero = $salesAtZero->toCents();
        $general = $salesAtGeneral->toCents();
        $total = $zero + $general;

        if ($total === 0) {
            return BigDecimal::zero()->toScale(self::SCALE);
        }

        return BigDecimal::of($zero)
            ->dividedBy(BigDecimal::of($total), self::SCALE, RoundingMode::HalfUp);
    }

    /**
     * Cara complementaria del factor (tarifa general).
     *
     * Se calcula como `1 − factor` en lugar de dividir de nuevo para que
     * ambas caras sumen exactamente `1.000000` y no se pierda un ulp por
     * redondear dos veces.
     */
    public static function complement(BigDecimal $factor): BigDecimal
    {
        if ($factor->isLessThan(0) || $factor->isGreaterThan(1)) {
            throw new InvalidArgumentException("El factor debe estar entre 0 y 1: {$factor}");
        }

        return BigDecimal::one()
            ->minus($factor)
            ->toScale(self::SCALE, RoundingMode::HalfUp);
    }

    /**
     * Aplica el factor a un importe (por ejemplo, el IVA de compras comunes)
     * con redondeo HALF_UP a centavos.
     */
    public static function apply(Money $amount, BigDecimal $factor): Money
    {
        return $amount->multipliedBy($factor);
    }

    private static function assertNotNegative(Money $amount, string $label): void
    {
        if ($amount->isNegative()) {
            throw new InvalidArgumentException("Las {$label} no pueden ser negativas: {$amount}");
        }
    }
}
