<?php

declare(strict_types=1);

namespace App\Domain\Sri\Enums;

/**
 * Tipos de comprobante que descarga el robot del portal del SRI.
 *
 * Los códigos son los de la ficha técnica; los slugs coinciden exactamente con
 * los nombres que usa `sri-txt-robot` en sus archivos.
 */
enum SriDocumentType: string
{
    case Invoice = '01';
    case PurchaseSettlement = '03';
    case CreditNote = '04';
    case DebitNote = '05';
    case RemissionGuide = '06';
    case Withholding = '07';

    public function slug(): string
    {
        return match ($this) {
            self::Invoice => 'facturas',
            self::PurchaseSettlement => 'liquidaciones-compra',
            self::CreditNote => 'notas-credito',
            self::DebitNote => 'notas-debito',
            self::RemissionGuide => 'guias-remision',
            self::Withholding => 'retenciones',
        };
    }

    public function label(): string
    {
        return match ($this) {
            self::Invoice => 'Factura',
            self::PurchaseSettlement => 'Liquidación de compra de bienes y prestación de servicios',
            self::CreditNote => 'Nota de crédito',
            self::DebitNote => 'Nota de débito',
            self::RemissionGuide => 'Guía de remisión',
            self::Withholding => 'Comprobante de retención',
        };
    }

    /**
     * @return list<string>
     */
    public static function codes(): array
    {
        return array_map(
            static fn (self $type): string => $type->value,
            self::cases(),
        );
    }
}
