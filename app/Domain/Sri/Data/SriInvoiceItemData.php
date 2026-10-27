<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

/**
 * Línea de detalle de un comprobante.
 *
 * `normalizedDescription` se calcula en el dominio (sin acentos, mayúsculas y
 * espacios colapsados) porque es la clave de las reglas de clasificación y de
 * la caché de IA; se guarda desnormalizada en `invoice_items`.
 */
final readonly class SriInvoiceItemData
{
    public function __construct(
        public int $lineNumber,
        public string $description,
        public string $normalizedDescription,
        public string $quantity,
        public string $unitPrice,
        public string $discount,
        public int $subtotalCents,
        public int $totalCents,
        public ?string $mainCode = null,
        public ?string $auxiliaryCode = null,
    ) {}
}
