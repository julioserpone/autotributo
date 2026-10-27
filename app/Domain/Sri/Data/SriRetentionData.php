<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

/**
 * Retención practicada en un comprobante de retención (tipo `07`).
 *
 * `taxType` es `IR` (`codigo` 1) o `IVA` (`codigo` 2) y `retentionCode` el
 * código de la tabla de retenciones vigente.
 */
final readonly class SriRetentionData
{
    public function __construct(
        public string $taxType,
        public string $retentionCode,
        public int $baseCents,
        public string $rate,
        public int $amountCents,
        public string $supportDocumentType,
        public string $supportDocumentNumber,
        public string $supportDocumentDate,
    ) {}

    public function isIncomeTax(): bool
    {
        return $this->taxType === 'IR';
    }
}
