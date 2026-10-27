<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

/**
 * Impuesto de cabecera del comprobante (`totalImpuesto`).
 *
 * `sriPercentageCode` (`codigoPorcentaje`) es distinto de la tarifa: ambos se
 * conservan porque el 104 se declara por código, no por tarifa.
 */
final readonly class SriInvoiceTaxData
{
    public function __construct(
        public string $taxCode,
        public string $sriPercentageCode,
        public string $rate,
        public int $baseCents,
        public int $amountCents,
    ) {}
}
