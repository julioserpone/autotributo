<?php

declare(strict_types=1);

namespace App\Integrations\Sri;

use App\Domain\Sri\Contracts\SriInvoiceParser;
use App\Domain\Sri\Data\SriInvoiceData;
use App\Domain\Sri\Data\SriInvoiceItemData;
use App\Domain\Sri\Data\SriInvoiceTaxData;
use App\Domain\Sri\Data\SriRetentionData;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Exceptions\SriXmlException;
use JulioSerpone\SriComprobantes\Comprobante\ComprobanteException;
use JulioSerpone\SriComprobantes\Comprobante\ComprobanteReader;
use JulioSerpone\SriComprobantes\Comprobante\Data\ComprobanteData;
use JulioSerpone\SriComprobantes\Comprobante\Data\ImpuestoData;
use JulioSerpone\SriComprobantes\Comprobante\Data\ItemData;
use JulioSerpone\SriComprobantes\Comprobante\Data\RetencionData;

/**
 * Adaptador del lector de comprobantes del paquete al modelo del dominio.
 *
 * La lectura del XML vive en `julioserpone/sri-comprobantes-php`
 * (`ComprobanteReader`); aquí solo se traduce su DTO al del dominio, se
 * mapean los códigos de comprobante y se empaqueta el error del paquete en la
 * excepción del dominio.
 */
final readonly class PackageSriInvoiceParser implements SriInvoiceParser
{
    private const TAX_TYPES = [
        '1' => 'IR',
        '2' => 'IVA',
        '3' => 'ICE',
        '5' => 'IRBPNR',
    ];

    public function __construct(private ComprobanteReader $reader = new ComprobanteReader) {}

    public function parse(string $xml): SriInvoiceData
    {
        try {
            return $this->map($this->reader->read($xml));
        } catch (ComprobanteException $exception) {
            throw SriXmlException::unreadable($exception);
        }
    }

    private function map(ComprobanteData $data): SriInvoiceData
    {
        $type = SriDocumentType::tryFrom($data->tipo);

        if ($type === null) {
            throw SriXmlException::unsupportedDocumentType($data->tipo);
        }

        return new SriInvoiceData(
            accessKey: $data->claveAcceso,
            documentType: $type,
            environment: $data->ambiente,
            authorizationNumber: $data->numeroAutorizacion ?? '',
            issueDate: $data->fechaEmision,
            issuerRuc: $data->rucEmisor,
            issuerName: $data->razonSocialEmisor,
            receiverRuc: $data->rucReceptor ?? '',
            receiverName: $data->razonSocialReceptor ?? '',
            establishment: $data->establecimiento,
            emissionPoint: $data->puntoEmision,
            sequential: $data->secuencial,
            subtotalCents: $data->subtotalCentavos,
            discountCents: $data->descuentoCentavos,
            taxCents: $data->impuestoCentavos,
            iceCents: $data->iceCentavos,
            irbpnrCents: $data->irbpnrCentavos,
            tipCents: $data->propinaCentavos,
            totalCents: $data->totalCentavos,
            currency: $data->moneda,
            items: array_map($this->item(...), $data->items),
            taxes: array_map($this->tax(...), $data->impuestos),
            relatedAccessKeys: $data->clavesRelacionadas,
            reason: $data->motivo,
            authorizationDate: $data->fechaAutorizacion,
            retentions: array_map($this->retention(...), $data->retenciones),
        );
    }

    private function item(ItemData $item): SriInvoiceItemData
    {
        return new SriInvoiceItemData(
            lineNumber: $item->numeroLinea,
            description: $item->descripcion,
            normalizedDescription: $item->descripcionNormalizada,
            quantity: $item->cantidad,
            unitPrice: $item->precioUnitario,
            discount: $item->descuento,
            subtotalCents: $item->subtotalCentavos,
            totalCents: $item->totalCentavos,
            mainCode: $item->codigoPrincipal,
            auxiliaryCode: $item->codigoAuxiliar,
        );
    }

    private function tax(ImpuestoData $tax): SriInvoiceTaxData
    {
        return new SriInvoiceTaxData(
            taxCode: $tax->codigo,
            sriPercentageCode: $tax->codigoPorcentaje,
            rate: $tax->tarifa,
            baseCents: $tax->baseCentavos,
            amountCents: $tax->valorCentavos,
        );
    }

    private function retention(RetencionData $retention): SriRetentionData
    {
        return new SriRetentionData(
            taxType: self::TAX_TYPES[$retention->codigo] ?? $retention->codigo,
            retentionCode: $retention->codigoRetencion,
            baseCents: $retention->baseCentavos,
            rate: $retention->porcentajeRetener,
            amountCents: $retention->valorRetenidoCentavos,
            supportDocumentType: $retention->tipoDocumento,
            supportDocumentNumber: $retention->numeroDocumento,
            supportDocumentDate: $retention->fechaEmisionDocumento,
        );
    }
}
