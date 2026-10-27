<?php

declare(strict_types=1);

namespace App\Domain\Sri\Parsers;

use App\Domain\Sri\Data\SriAccessKey;
use App\Domain\Sri\Data\SriInvoiceData;
use App\Domain\Sri\Data\SriInvoiceItemData;
use App\Domain\Sri\Data\SriInvoiceTaxData;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Exceptions\SriXmlException;
use DOMDocument;
use DOMElement;
use DOMNode;
use DOMXPath;

/**
 * Interpreta el XML autorizado de un comprobante del SRI.
 *
 * Soportado en esta etapa: factura (`01`), nota de crédito (`04`) y nota de
 * débito (`05`). Liquidación de compra y comprobante de retención llegan en la
 * siguiente iteración de F2-06.
 *
 * Seguridad: el documento se carga con `LIBXML_NONET`, sin `LIBXML_NOENT` y
 * sin DTD, así que las entidades externas no se resuelven (XXE). No se
 * registra el contenido del XML.
 */
final readonly class SriXmlParser
{
    public const MAX_BYTES = 5_242_880;

    /** @var array<string, SriDocumentType> */
    private const ROOTS = [
        'factura' => SriDocumentType::Invoice,
        'notaCredito' => SriDocumentType::CreditNote,
        'notaDebito' => SriDocumentType::DebitNote,
    ];

    /**
     * @throws SriXmlException
     */
    public function parse(string $xml): SriInvoiceData
    {
        if (strlen($xml) > self::MAX_BYTES) {
            throw SriXmlException::tooLarge(strlen($xml), self::MAX_BYTES);
        }

        $document = $this->load($xml);
        $root = $document->documentElement;

        if ($root === null) {
            throw SriXmlException::unreadable();
        }

        $rootName = (string) $root->localName;
        $type = self::ROOTS[$rootName] ?? throw SriXmlException::unsupportedDocumentType($rootName);
        $xpath = new DOMXPath($document);
        $infoTributaria = $this->require($xpath, 'infoTributaria', 'infoTributaria');

        $accessKey = $this->text($xpath, 'claveAcceso', $infoTributaria, 'claveAcceso');
        new SriAccessKey($accessKey);

        $info = $this->infoBlock($xpath, $type);

        return new SriInvoiceData(
            accessKey: $accessKey,
            documentType: $type,
            environment: $this->text($xpath, 'ambiente', $infoTributaria, 'ambiente'),
            authorizationNumber: $this->optionalText($xpath, 'numeroAutorizacion', null) ?? '',
            issueDate: SriXmlValues::date($this->text($xpath, 'fechaEmision', $info, 'fechaEmision'), 'fechaEmision'),
            issuerRuc: $this->text($xpath, 'ruc', $infoTributaria, 'ruc'),
            issuerName: $this->text($xpath, 'razonSocial', $infoTributaria, 'razonSocial'),
            receiverRuc: $this->receiverRuc($xpath, $type),
            receiverName: $this->optionalText($xpath, 'razonSocialComprador', $info) ?? '',
            establishment: $this->text($xpath, 'estab', $infoTributaria, 'estab'),
            emissionPoint: $this->text($xpath, 'ptoEmi', $infoTributaria, 'ptoEmi'),
            sequential: $this->text($xpath, 'secuencial', $infoTributaria, 'secuencial'),
            subtotalCents: $this->cents($xpath, 'totalSinImpuestos', $info),
            discountCents: $this->cents($xpath, 'totalDescuento', $info),
            taxCents: $this->taxCents($xpath, $info),
            iceCents: $this->optionalCents($xpath, 'totalIce', $info),
            irbpnrCents: $this->optionalCents($xpath, 'totalIRBPNR', $info),
            tipCents: $this->optionalCents($xpath, 'propina', $info),
            totalCents: $this->cents($xpath, 'importeTotal', $info),
            currency: $this->optionalText($xpath, 'moneda', $info) ?? 'USD',
            items: $this->items($xpath, $info),
            taxes: $this->taxes($xpath, $info),
            relatedAccessKeys: $this->relatedAccessKeys($xpath, $type),
            reason: $this->optionalText($xpath, 'motivo', $info),
            authorizationDate: $this->authorizationDate($xpath),
        );
    }

    private function load(string $xml): DOMDocument
    {
        if (trim($xml) === '') {
            throw SriXmlException::unreadable();
        }

        $previous = libxml_use_internal_errors(true);
        libxml_clear_errors();

        $document = new DOMDocument;
        $document->resolveExternals = false;
        $document->substituteEntities = false;
        $loaded = $document->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($previous);

        if (! $loaded) {
            throw SriXmlException::unreadable();
        }

        return $document;
    }

    private function infoBlock(DOMXPath $xpath, SriDocumentType $type): DOMElement
    {
        return match ($type) {
            SriDocumentType::Invoice => $this->require($xpath, 'infoFactura', 'infoFactura'),
            SriDocumentType::CreditNote => $this->require($xpath, 'infoNotaCredito', 'infoNotaCredito'),
            SriDocumentType::DebitNote => $this->require($xpath, 'infoNotaDebito', 'infoNotaDebito'),
            default => throw SriXmlException::unsupportedDocumentType($type->value),
        };
    }

    /**
     * En notas de crédito y débito el receptor es el comprador original.
     */
    private function receiverRuc(DOMXPath $xpath, SriDocumentType $type): string
    {
        $info = $this->infoBlock($xpath, $type);

        return $this->optionalText($xpath, 'identificacionComprador', $info) ?? '';
    }

    /**
     * @return list<SriInvoiceItemData>
     */
    private function items(DOMXPath $xpath, DOMElement $info): array
    {
        $detalles = $this->optionalElement($xpath, 'detalles');
        $items = [];

        if ($detalles === null) {
            return $items;
        }

        $lineNumber = 0;

        foreach ($xpath->query('*[local-name()="detalle"]', $detalles) ?: [] as $detalle) {
            if (! $detalle instanceof DOMElement) {
                continue;
            }

            $lineNumber++;
            $description = $this->text($xpath, 'descripcion', $detalle, 'descripcion');
            $subtotal = $this->cents($xpath, 'precioTotalSinImpuesto', $detalle);
            $discount = $this->optionalCents($xpath, 'descuento', $detalle);
            $taxes = $this->taxes($xpath, $detalle);

            $items[] = new SriInvoiceItemData(
                lineNumber: $lineNumber,
                description: $description,
                normalizedDescription: SriXmlValues::normalizeDescription($description),
                quantity: $this->optionalText($xpath, 'cantidad', $detalle) ?? '0',
                unitPrice: $this->optionalText($xpath, 'precioUnitario', $detalle) ?? '0',
                discount: $this->optionalText($xpath, 'descuento', $detalle) ?? '0',
                subtotalCents: $subtotal,
                // `precioTotalSinImpuesto` ya incluye el descuento del emisor:
                // la línea suma solo sus impuestos.
                totalCents: $subtotal + array_sum(array_map(
                    static fn (SriInvoiceTaxData $tax): int => $tax->amountCents,
                    $taxes,
                )),
                mainCode: $this->optionalText($xpath, 'codigoPrincipal', $detalle),
                auxiliaryCode: $this->optionalText($xpath, 'codigoAuxiliar', $detalle),
            );
        }

        return $items;
    }

    /**
     * @return list<SriInvoiceTaxData>
     */
    private function taxes(DOMXPath $xpath, DOMElement $parent): array
    {
        // En la cabecera los impuestos cuelgan de `totalConImpuestos`; en cada
        // línea de detalle, de `impuestos`.
        $totalConImpuestos = $this->optionalElement($xpath, 'totalConImpuestos', $parent);

        if ($totalConImpuestos !== null) {
            return $this->taxNodes($xpath, $totalConImpuestos, 'totalImpuesto');
        }

        $impuestos = $this->optionalElement($xpath, 'impuestos', $parent);

        return $impuestos === null ? [] : $this->taxNodes($xpath, $impuestos, 'impuesto');
    }

    /**
     * @return list<SriInvoiceTaxData>
     */
    private function taxNodes(DOMXPath $xpath, DOMElement $container, string $node): array
    {
        $taxes = [];

        foreach ($xpath->query('*[local-name()="'.$node.'"]', $container) ?: [] as $impuesto) {
            if (! $impuesto instanceof DOMElement) {
                continue;
            }

            $taxes[] = new SriInvoiceTaxData(
                taxCode: $this->text($xpath, 'codigo', $impuesto, 'codigo'),
                sriPercentageCode: $this->text($xpath, 'codigoPorcentaje', $impuesto, 'codigoPorcentaje'),
                rate: $this->optionalText($xpath, 'tarifa', $impuesto) ?? '0',
                baseCents: $this->cents($xpath, 'baseImponible', $impuesto),
                amountCents: $this->cents($xpath, 'valor', $impuesto),
            );
        }

        return $taxes;
    }

    private function taxCents(DOMXPath $xpath, DOMElement $info): int
    {
        return array_sum(array_map(
            static fn (SriInvoiceTaxData $tax): int => $tax->amountCents,
            $this->taxes($xpath, $info),
        ));
    }

    /**
     * @return list<string>
     */
    private function relatedAccessKeys(DOMXPath $xpath, SriDocumentType $type): array
    {
        if (! in_array($type, [SriDocumentType::CreditNote, SriDocumentType::DebitNote], true)) {
            return [];
        }

        $keys = [];

        foreach ($xpath->query('/*[local-name()="notaCredito" or local-name()="notaDebito"]/*[local-name()="infoNotaModificada"]/*[local-name()="numDocModificado"]') ?: [] as $node) {
            if (! $node instanceof DOMNode) {
                continue;
            }

            $value = trim($node->textContent);

            if ($value !== '') {
                $keys[] = $value;
            }
        }

        return $keys;
    }

    private function authorizationDate(DOMXPath $xpath): ?string
    {
        $value = $this->optionalText($xpath, 'fechaAutorizacion', null);

        return $value === null ? null : SriXmlValues::dateTime($value, 'fechaAutorizacion');
    }

    private function require(DOMXPath $xpath, string $path, string $field): DOMElement
    {
        $node = $this->optionalElement($xpath, $path);

        if ($node === null) {
            throw SriXmlException::missingField($field);
        }

        return $node;
    }

    private function optionalElement(DOMXPath $xpath, string $path, ?DOMElement $context = null): ?DOMElement
    {
        $node = $this->optionalNode($xpath, $path, $context);

        return $node instanceof DOMElement ? $node : null;
    }

    private function text(DOMXPath $xpath, string $path, ?DOMElement $context, string $field): string
    {
        $value = $this->optionalText($xpath, $path, $context);

        if ($value === null) {
            throw SriXmlException::missingField($field);
        }

        return $value;
    }

    private function optionalText(DOMXPath $xpath, string $path, ?DOMElement $context): ?string
    {
        $node = $this->optionalNode($xpath, $path, $context);

        if ($node === null) {
            return null;
        }

        $value = trim($node->textContent);

        return $value === '' ? null : $value;
    }

    /**
     * Resuelve un campo por nombre local, sin depender del namespace que el
     * comprobante declare en su raíz. Los comprobantes del SRI a veces llevan
     * `xmlns` y a veces no.
     */
    private function optionalNode(DOMXPath $xpath, string $path, ?DOMElement $context): ?DOMNode
    {
        $steps = array_map(
            static fn (string $step): string => '*[local-name()="'.$step.'"]',
            explode('/', ltrim($path, '/')),
        );

        // Sin contexto se busca desde la raíz; con contexto, desde el propio
        // nodo (hijos directos).
        $query = $context === null ? '//'.implode('/', $steps) : implode('/', $steps);

        $nodes = $context === null ? $xpath->query($query) : $xpath->query($query, $context);
        $node = $nodes === false ? null : $nodes->item(0);

        return $node instanceof DOMNode ? $node : null;
    }

    private function cents(DOMXPath $xpath, string $path, ?DOMElement $context): int
    {
        return SriXmlValues::cents((string) $this->text($xpath, $path, $context, $path), $path);
    }

    private function optionalCents(DOMXPath $xpath, string $path, ?DOMElement $context): int
    {
        $value = $this->optionalText($xpath, $path, $context);

        return $value === null ? 0 : SriXmlValues::cents($value, $path);
    }
}
