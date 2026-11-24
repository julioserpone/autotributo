<?php

declare(strict_types=1);

use App\Domain\Sri\Data\SriFiscalDocumentData;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Exceptions\SriXmlException;
use App\Integrations\Sri\PackageSriFiscalDocumentParser;
use Tests\Support\Sri\AccessKeyFactory;

/**
 * El parser del dominio es el paquete `julioserpone/sri-comprobantes-php`
 * envuelto por `PackageSriFiscalDocumentParser`. Estos tests verifican la traducción
 * al modelo de la aplicación y, de paso, el contrato del paquete.
 */
function xmlFixture(string $path, array $replace = []): string
{
    $xml = file_get_contents(__DIR__.'/../../Fixtures/xml/'.$path);

    if ($xml === false) {
        throw new RuntimeException("No se pudo leer el fixture {$path}.");
    }

    return strtr($xml, $replace + [
        '{{CLAVE}}' => AccessKeyFactory::make(),
        '{{CLAVE_ORIGEN}}' => AccessKeyFactory::make(sequential: '000000009'),
    ]);
}

function keyed(string $path, string $type): string
{
    return strtr(
        (string) file_get_contents(__DIR__.'/../../Fixtures/xml/'.$path),
        ['{{CLAVE}}' => AccessKeyFactory::make(type: $type)],
    );
}

beforeEach(function (): void {
    $this->parser = new PackageSriFiscalDocumentParser;
});

it('interpreta una factura completa', function (): void {
    $fiscal_document = $this->parser->parse(xmlFixture('fiscal_document/factura.xml'));

    expect($fiscal_document)->toBeInstanceOf(SriFiscalDocumentData::class)
        ->and($fiscal_document->documentType)->toBe(SriDocumentType::FiscalDocument)
        ->and($fiscal_document->accessKey)->toBe(AccessKeyFactory::make())
        ->and($fiscal_document->environment)->toBe('2')
        ->and($fiscal_document->issueDate)->toBe('2026-09-15')
        ->and($fiscal_document->issuerRuc)->toBe('1758871873001')
        ->and($fiscal_document->issuerName)->toBe('PROVEEDOR DE PRUEBA S.A.')
        ->and($fiscal_document->receiverRuc)->toBe('1790012345001')
        ->and($fiscal_document->receiverName)->toBe('CLIENTE DE PRUEBA CIA. LTDA.')
        ->and($fiscal_document->establishment)->toBe('001')
        ->and($fiscal_document->emissionPoint)->toBe('001')
        ->and($fiscal_document->sequential)->toBe('000000123')
        ->and($fiscal_document->currency)->toBe('USD');
});

it('convierte los totales a centavos sin float', function (): void {
    $fiscal_document = $this->parser->parse(xmlFixture('fiscal_document/factura.xml'));

    expect($fiscal_document->subtotalCents)->toBe(9_000)
        ->and($fiscal_document->discountCents)->toBe(0)
        ->and($fiscal_document->taxCents)->toBe(1_350)
        ->and($fiscal_document->tipCents)->toBe(0)
        ->and($fiscal_document->totalCents)->toBe(10_350)
        ->and($fiscal_document->totalsMismatch())->toBeFalse();
});

it('no vuelve a restar el descuento global: totalSinImpuestos ya es neto', function (): void {
    // Base 90,00 (neta de un descuento global de 10,00) + IVA 13,50 = 103,50.
    $fiscal_document = $this->parser->parse(xmlFixture('fiscal_document/factura.xml'));

    expect($fiscal_document->subtotalCents)->toBe(9_000)
        ->and($fiscal_document->discountCents)->toBe(0)
        ->and($fiscal_document->expectedTotalCents())->toBe(10_350)
        ->and($fiscal_document->totalsMismatch())->toBeFalse();
});

it('ignora el totalDescuento informado al cuadrar (no lo resta dos veces)', function (): void {
    $xml = str_replace(
        '<totalDescuento>0.00</totalDescuento>',
        '<totalDescuento>10.00</totalDescuento>',
        xmlFixture('fiscal_document/factura.xml'),
    );

    $fiscal_document = $this->parser->parse($xml);

    expect($fiscal_document->discountCents)->toBe(1_000)
        ->and($fiscal_document->expectedTotalCents())->toBe(10_350)
        ->and($fiscal_document->totalCents)->toBe(10_350)
        ->and($fiscal_document->totalsMismatch())->toBeFalse();
});

it('cuadra una factura real con descuento global sin marcarla inconsistente', function (): void {
    // Caso de producción: importeTotal 2,62 = totalSinImpuestos 2,62, IVA 0
    // y totalDescuento 0,89. Antes se calculaba un esperado de 1,73.
    $xml = str_replace(
        [
            '<totalSinImpuestos>90.00</totalSinImpuestos>',
            '<importeTotal>103.50</importeTotal>',
            '<baseImponible>90.00</baseImponible>',
            '<valor>13.50</valor>',
            '<baseImponible>10.00</baseImponible>',
        ],
        [
            '<totalSinImpuestos>2.62</totalSinImpuestos>',
            '<importeTotal>2.62</importeTotal>',
            '<baseImponible>2.62</baseImponible>',
            '<valor>0.00</valor>',
            '<baseImponible>0.00</baseImponible>',
        ],
        xmlFixture('fiscal_document/factura.xml'),
    );

    $withDiscount = str_replace('<totalDescuento>0.00</totalDescuento>', '<totalDescuento>0.89</totalDescuento>', $xml);

    $fiscal_document = $this->parser->parse($withDiscount);

    expect($fiscal_document->totalCents)->toBe(262)
        ->and($fiscal_document->discountCents)->toBe(89)
        ->and($fiscal_document->expectedTotalCents())->toBe(262)
        ->and($fiscal_document->totalsMismatch())->toBeFalse();
});

it('interpreta los ítems y sus impuestos', function (): void {
    $fiscal_document = $this->parser->parse(xmlFixture('fiscal_document/factura.xml'));
    $first = $fiscal_document->items[0];

    expect($fiscal_document->items)->toHaveCount(2)
        ->and($first->lineNumber)->toBe(1)
        ->and($first->description)->toBe('Servicios de consultoría contable')
        ->and($first->normalizedDescription)->toBe('SERVICIOS DE CONSULTORIA CONTABLE')
        ->and($first->mainCode)->toBe('SRV-001')
        ->and($first->subtotalCents)->toBe(9_000)
        ->and($first->totalCents)->toBe(10_350)
        ->and($fiscal_document->itemsSubtotalCents())->toBe(10_000);
});

it('normaliza los impuestos de cabecera con su código de porcentaje', function (): void {
    $fiscal_document = $this->parser->parse(xmlFixture('fiscal_document/factura.xml'));

    expect($fiscal_document->taxes)->toHaveCount(2)
        ->and($fiscal_document->taxes[0]->taxCode)->toBe('2')
        ->and($fiscal_document->taxes[0]->sriPercentageCode)->toBe('4')
        ->and($fiscal_document->taxes[0]->baseCents)->toBe(9_000)
        ->and($fiscal_document->taxes[0]->amountCents)->toBe(1_350)
        ->and($fiscal_document->taxes[1]->sriPercentageCode)->toBe('0')
        ->and($fiscal_document->taxesTotalCents())->toBe(1_350);
});

it('marca los totales inconsistentes en lugar de corregirlos', function (): void {
    $xml = str_replace('<importeTotal>103.50</importeTotal>', '<importeTotal>104.00</importeTotal>', xmlFixture('fiscal_document/factura.xml'));

    $fiscal_document = $this->parser->parse($xml);

    expect($fiscal_document->totalsMismatch())->toBeTrue()
        ->and($fiscal_document->totalCents)->toBe(10_400)
        ->and($fiscal_document->expectedTotalCents())->toBe(10_350);
});

it('interpreta una nota de crédito y su documento modificado', function (): void {
    $note = $this->parser->parse(xmlFixture('credit_note/nota-credito.xml'));

    expect($note->documentType)->toBe(SriDocumentType::CreditNote)
        ->and($note->relatedAccessKeys)->toBe([AccessKeyFactory::make(sequential: '000000009')])
        ->and($note->items)->toHaveCount(1)
        ->and($note->totalCents)->toBe(1_350)
        ->and($note->reason)->toBe('Devolución de servicios');
});

it('interpreta una nota de débito', function (): void {
    $note = $this->parser->parse(xmlFixture('debit_note/nota-debito.xml'));

    expect($note->documentType)->toBe(SriDocumentType::DebitNote)
        ->and($note->relatedAccessKeys)->toHaveCount(1);
});

it('interpreta una liquidación de compra', function (): void {
    $settlement = $this->parser->parse(keyed('purchase_settlement/liquidacion-compra.xml', '03'));

    expect($settlement->documentType)->toBe(SriDocumentType::PurchaseSettlement)
        ->and($settlement->receiverName)->toBe('PROVEEDOR DE PRUEBA S.A.')
        ->and($settlement->subtotalCents)->toBe(20_000)
        ->and($settlement->totalCents)->toBe(23_000)
        ->and($settlement->totalsMismatch())->toBeFalse();
});

it('interpreta una guía de remisión', function (): void {
    $guide = $this->parser->parse(keyed('remission_guide/guia-remision.xml', '06'));

    expect($guide->documentType)->toBe(SriDocumentType::RemissionGuide)
        ->and($guide->issueDate)->toBe('2026-09-19')
        ->and($guide->receiverRuc)->toBe('1790012345001')
        ->and($guide->items)->toHaveCount(1);
});

it('interpreta un comprobante de retención con sus retenciones', function (): void {
    $retention = $this->parser->parse(keyed('retention/comprobante-retencion.xml', '07'));

    expect($retention->documentType)->toBe(SriDocumentType::Withholding)
        ->and($retention->retentions)->toHaveCount(2)
        ->and($retention->retentions[0]->taxType)->toBe('IR')
        ->and($retention->retentions[0]->retentionCode)->toBe('303')
        ->and($retention->retentions[0]->rate)->toBe('2.00')
        ->and($retention->retentions[0]->amountCents)->toBe(200)
        ->and($retention->retentions[0]->supportDocumentNumber)->toBe('001001000000123')
        ->and($retention->retentions[1]->taxType)->toBe('IVA')
        ->and($retention->retentions[1]->amountCents)->toBe(2_700)
        ->and($retention->retentionsTotalCents())->toBe(2_900)
        ->and($retention->totalCents)->toBe(2_900)
        ->and($retention->totalsMismatch())->toBeFalse();
});

it('rechaza un XML malformado', function (): void {
    expect(fn () => $this->parser->parse('<factura><infoTributaria>'))
        ->toThrow(SriXmlException::class, 'no es válido');
});

it('rechaza un comprobante sin información tributaria', function (): void {
    $xml = preg_replace('#<infoTributaria>.*?</infoTributaria>#s', '', xmlFixture('fiscal_document/factura.xml'));

    expect(fn () => $this->parser->parse((string) $xml))
        ->toThrow(SriXmlException::class, 'no es válido');
});

it('rechaza una clave de acceso inválida en el XML', function (): void {
    $xml = xmlFixture('fiscal_document/factura.xml', ['{{CLAVE}}' => AccessKeyFactory::withBrokenCheckDigit()]);

    expect(fn () => $this->parser->parse($xml))->toThrow(SriXmlException::class);
});

it('no resuelve entidades externas (XXE)', function (): void {
    $xml = <<<'XML'
<?xml version="1.0"?>
<!DOCTYPE factura [<!ENTITY xxe SYSTEM "file:///etc/passwd">]>
<factura><infoTributaria><claveAcceso>&xxe;</claveAcceso></infoTributaria></factura>
XML;

    try {
        $this->parser->parse($xml);
        $this->fail('El parser debía rechazar el documento.');
    } catch (SriXmlException $exception) {
        expect($exception->getMessage())->not->toContain('root:');
    }
});

it('rechaza un XML que supera el tamaño máximo', function (): void {
    expect(fn () => $this->parser->parse(str_repeat('a', 5_242_881)))
        ->toThrow(SriXmlException::class, 'no es válido');
});
