<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

use App\Domain\Sri\Enums\SriDocumentType;

/**
 * Cabecera y totales de un comprobante ya interpretado.
 *
 * Todos los montos van en centavos (`bigint`), nunca en `float`, según el
 * modelo de datos. `totalsMismatch` indica que el emisor publicó totales
 * inconsistentes: el comprobante se importa igual (no se corrige en silencio)
 * y se marca para un hallazgo fiscal.
 */
final readonly class SriInvoiceData
{
    /**
     * @param  list<SriInvoiceItemData>  $items
     * @param  list<SriInvoiceTaxData>  $taxes
     * @param  list<string>  $relatedAccessKeys  Claves de las facturas ajustadas (notas de crédito y débito)
     */
    public function __construct(
        public string $accessKey,
        public SriDocumentType $documentType,
        public string $environment,
        public string $authorizationNumber,
        public string $issueDate,
        public string $issuerRuc,
        public string $issuerName,
        public string $receiverRuc,
        public string $receiverName,
        public string $establishment,
        public string $emissionPoint,
        public string $sequential,
        public int $subtotalCents,
        public int $discountCents,
        public int $taxCents,
        public int $iceCents,
        public int $irbpnrCents,
        public int $tipCents,
        public int $totalCents,
        public string $currency,
        public array $items = [],
        public array $taxes = [],
        public array $relatedAccessKeys = [],
        public ?string $reason = null,
        public ?string $authorizationDate = null,
    ) {}

    /**
     * `total_cents` debe igualar subtotal − descuento + impuestos + ICE +
     * IRBPNR + propina (invariante #1 de `docs/03-data-model.md`).
     */
    public function totalsMismatch(): bool
    {
        return $this->totalCents !== $this->expectedTotalCents();
    }

    public function expectedTotalCents(): int
    {
        return $this->subtotalCents
            - $this->discountCents
            + $this->taxCents
            + $this->iceCents
            + $this->irbpnrCents
            + $this->tipCents;
    }

    public function itemsSubtotalCents(): int
    {
        return array_sum(array_map(
            static fn (SriInvoiceItemData $item): int => $item->subtotalCents,
            $this->items,
        ));
    }

    public function taxesTotalCents(): int
    {
        return array_sum(array_map(
            static fn (SriInvoiceTaxData $tax): int => $tax->amountCents,
            $this->taxes,
        ));
    }
}
