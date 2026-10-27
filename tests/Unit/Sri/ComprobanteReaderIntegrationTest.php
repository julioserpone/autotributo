<?php

declare(strict_types=1);

use App\Domain\Sri\Data\SriInvoiceData;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Exceptions\SriXmlException;
use App\Integrations\Sri\PackageSriInvoiceParser;
use Tests\Support\Sri\AccessKeyFactory;

/**
 * El parser del dominio es el paquete `julioserpone/sri-comprobantes-php`
 * envuelto por `PackageSriInvoiceParser`. Estos tests verifican la traducción
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
    $this->parser = new PackageSriInvoiceParser;
});

it('interpreta una factura completa', function (): void {
    $invoice = $this->parser->parse(xmlFixture('invoice/factura.xml'));

    expect($invoice)->toBeInstanceOf(SriInvoiceData::class)
        ->and($invoice->documentType)->toBe(SriDocumentType::Invoice)
        ->and($invoice->accessKey)->toBe(AccessKeyFactory::make())
        ->and($invoice->environment)->toBe('2')
        ->and($invoice->issueDate)->toBe('2026-09-15')
        ->and($invoice->issuerRuc)->toBe('1758871873001')
        ->and($invoice->issuerName)->toBe('PROVEEDOR DE PRUEBA S.A.')
        ->and($invoice->receiverRuc)->toBe('1790012345001')
        ->and($invoice->receiverName)->toBe('CLIENTE DE PRUEBA CIA. LTDA.')
        ->and($invoice->establishment)->toBe('001')
        ->and($invoice->emissionPoint)->toBe('001')
        ->and($invoice->sequential)->toBe('000000123')
        ->and($invoice->currency)->toBe('USD');
});

it('convierte los totales a centavos sin float', function (): void {
    $invoice = $this->parser->parse(xmlFixture('invoice/factura.xml'));

    expect($invoice->subtotalCents)->toBe(10_000)
        ->and($invoice->discountCents)->toBe(1_000)
        ->and($invoice->taxCents)->toBe(1_350)
        ->and($invoice->tipCents)->toBe(0)
        ->and($invoice->totalCents)->toBe(10_350)
        ->and($invoice->totalsMismatch())->toBeFalse();
});

it('interpreta los ítems y sus impuestos', function (): void {
    $invoice = $this->parser->parse(xmlFixture('invoice/factura.xml'));
    $first = $invoice->items[0];

    expect($invoice->items)->toHaveCount(2)
        ->and($first->lineNumber)->toBe(1)
        ->and($first->description)->toBe('Servicios de consultoría contable')
        ->and($first->normalizedDescription)->toBe('SERVICIOS DE CONSULTORIA CONTABLE')
        ->and($first->mainCode)->toBe('SRV-001')
        ->and($first->subtotalCents)->toBe(9_000)
        ->and($first->totalCents)->toBe(10_350)
        ->and($invoice->itemsSubtotalCents())->toBe(10_000);
});

it('normaliza los impuestos de cabecera con su código de porcentaje', function (): void {
    $invoice = $this->parser->parse(xmlFixture('invoice/factura.xml'));

    expect($invoice->taxes)->toHaveCount(2)
        ->and($invoice->taxes[0]->taxCode)->toBe('2')
        ->and($invoice->taxes[0]->sriPercentageCode)->toBe('4')
        ->and($invoice->taxes[0]->baseCents)->toBe(9_000)
        ->and($invoice->taxes[0]->amountCents)->toBe(1_350)
        ->and($invoice->taxes[1]->sriPercentageCode)->toBe('0')
        ->and($invoice->taxesTotalCents())->toBe(1_350);
});

it('marca los totales inconsistentes en lugar de corregirlos', function (): void {
    $xml = str_replace('<importeTotal>103.50</importeTotal>', '<importeTotal>104.00</importeTotal>', xmlFixture('invoice/factura.xml'));

    $invoice = $this->parser->parse($xml);

    expect($invoice->totalsMismatch())->toBeTrue()
        ->and($invoice->totalCents)->toBe(10_400)
        ->and($invoice->expectedTotalCents())->toBe(10_350);
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
    $xml = preg_replace('#<infoTributaria>.*?</infoTributaria>#s', '', xmlFixture('invoice/factura.xml'));

    expect(fn () => $this->parser->parse((string) $xml))
        ->toThrow(SriXmlException::class, 'no es válido');
});

it('rechaza una clave de acceso inválida en el XML', function (): void {
    $xml = xmlFixture('invoice/factura.xml', ['{{CLAVE}}' => AccessKeyFactory::withBrokenCheckDigit()]);

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
