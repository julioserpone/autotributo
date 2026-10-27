<?php

declare(strict_types=1);

use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Exceptions\SriXmlException;
use App\Domain\Sri\Parsers\SriXmlParser;
use Tests\Support\Sri\AccessKeyFactory;

/**
 * Carga un fixture y sustituye las claves de acceso por las del período del
 * caso: el XML del SRI siempre trae la clave real y el parser la valida.
 *
 * @param  array<string, string>  $replace
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

beforeEach(function (): void {
    $this->parser = new SriXmlParser;
});

it('interpreta una factura completa', function (): void {
    $invoice = $this->parser->parse(xmlFixture('invoice/factura.xml'));

    expect($invoice->documentType)->toBe(SriDocumentType::Invoice)
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
        ->and($note->totalCents)->toBe(1_350);
});

it('interpreta una nota de débito', function (): void {
    $note = $this->parser->parse(xmlFixture('debit_note/nota-debito.xml'));

    expect($note->documentType)->toBe(SriDocumentType::DebitNote)
        ->and($note->relatedAccessKeys)->toHaveCount(1);
});

it('rechaza un XML malformado', function (): void {
    expect(fn () => $this->parser->parse('<factura><infoTributaria>'))
        ->toThrow(SriXmlException::class, 'no es válido');
});

it('rechaza un nodo raíz no soportado', function (): void {
    $xml = str_replace(['<factura', '</factura>'], ['<guiaRemision', '</guiaRemision>'], xmlFixture('invoice/factura.xml'));

    expect(fn () => $this->parser->parse($xml))
        ->toThrow(SriXmlException::class, 'no está soportado');
});

it('rechaza un comprobante sin infoTributaria', function (): void {
    $xml = preg_replace('#<infoTributaria>.*?</infoTributaria>#s', '', xmlFixture('invoice/factura.xml'));

    expect(fn () => $this->parser->parse((string) $xml))
        ->toThrow(SriXmlException::class, 'infoTributaria');
});

it('rechaza una clave de acceso inválida en el XML', function (): void {
    $xml = xmlFixture('invoice/factura.xml', ['{{CLAVE}}' => AccessKeyFactory::withBrokenCheckDigit()]);

    expect(fn () => $this->parser->parse($xml))
        ->toThrow(InvalidArgumentException::class, 'dígito verificador');
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
    } catch (SriXmlException|InvalidArgumentException $exception) {
        expect($exception->getMessage())->not->toContain('root:');
    }
});

it('rechaza un XML que supera el tamaño máximo', function (): void {
    expect(fn () => $this->parser->parse(str_repeat('a', 5_242_881)))
        ->toThrow(SriXmlException::class, 'tamaño máximo');
});
