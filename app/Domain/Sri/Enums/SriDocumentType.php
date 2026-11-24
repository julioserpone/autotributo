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
    case FiscalDocument = '01';
    case PurchaseSettlement = '03';
    case CreditNote = '04';
    case DebitNote = '05';
    case RemissionGuide = '06';
    case Withholding = '07';

    public function slug(): string
    {
        return match ($this) {
            self::FiscalDocument => 'facturas',
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
            self::FiscalDocument => 'Factura',
            self::PurchaseSettlement => 'Liquidación de compra de bienes y prestación de servicios',
            self::CreditNote => 'Nota de crédito',
            self::DebitNote => 'Nota de débito',
            self::RemissionGuide => 'Guía de remisión',
            self::Withholding => 'Comprobante de retención',
        };
    }

    /**
     * Dirección tributaria (`purchases`/`sales`) del comprobante.
     *
     * El comprobante guarda `direction` desde la perspectiva del emisor
     * (`received`/`issued`). La liquidación de compra siempre es una compra,
     * aunque la emita el comprador; el comprobante de retención invierte la
     * lectura: emitido por la empresa es una retención practicada y recibido
     * es una retención que le practicaron.
     */
    public function tradeDirection(string $documentDirection): string
    {
        return match ($this) {
            self::PurchaseSettlement => 'purchases',
            self::Withholding => $documentDirection === 'issued' ? 'purchases' : 'sales',
            default => $documentDirection === 'issued' ? 'sales' : 'purchases',
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

    /**
     * Tipos que el robot puede descargar del portal de recibidos.
     *
     * La guía de remisión (06) existe en la ficha técnica y el parser la
     * entiende, pero el portal del SRI no la ofrece en el desplegable de
     * recibidos: si se envía, la invocación completa del robot falla.
     *
     * @return list<string>
     */
    public static function syncableCodes(): array
    {
        return [
            self::FiscalDocument->value,
            self::PurchaseSettlement->value,
            self::CreditNote->value,
            self::DebitNote->value,
            self::Withholding->value,
        ];
    }
}
