<?php

declare(strict_types=1);

use App\Domain\Sri\Data\SriAccessKey;
use App\Domain\Sri\Enums\SriDocumentType;
use Tests\Support\Sri\AccessKeyFactory;

it('decodifica la estructura de una clave de acceso válida', function (): void {
    $key = new SriAccessKey(AccessKeyFactory::make());

    expect($key->documentType)->toBe(SriDocumentType::Invoice)
        ->and($key->issuedAt->format('Y-m-d'))->toBe('2026-09-15')
        ->and($key->period())->toBe('2026-09')
        ->and($key->issuerRuc)->toBe('1758871873001')
        ->and($key->environment)->toBe('2')
        ->and($key->isProduction())->toBeTrue()
        ->and($key->establishment)->toBe('001')
        ->and($key->emissionPoint)->toBe('001')
        ->and($key->sequential)->toBe('000000123')
        ->and($key->emissionType)->toBe('1');
});

it('decodifica los tipos de comprobante que descarga el robot', function (string $type, SriDocumentType $expected): void {
    expect((new SriAccessKey(AccessKeyFactory::make(type: $type)))->documentType)->toBe($expected);
})->with([
    'factura' => ['01', SriDocumentType::Invoice],
    'liquidación' => ['03', SriDocumentType::PurchaseSettlement],
    'nota de crédito' => ['04', SriDocumentType::CreditNote],
    'nota de débito' => ['05', SriDocumentType::DebitNote],
    'retención' => ['07', SriDocumentType::Withholding],
]);

it('acepta claves con espacios alrededor y en el ambiente de pruebas', function (): void {
    $value = AccessKeyFactory::make(environment: '1');
    $key = new SriAccessKey(" {$value} ");

    expect($key->value)->toBe($value)
        ->and($key->isProduction())->toBeFalse();
});

it('rechaza claves mal formadas', function (string $key, string $reason): void {
    expect(fn () => new SriAccessKey($key))->toThrow(InvalidArgumentException::class, $reason);
})->with([
    'longitud' => fn () => [str_repeat('1', 48), '49 dígitos'],
    'caracteres' => fn () => [str_pad('1758871873001A0100120260915000000001238765432101', 49, '0'), '49 dígitos'],
    'tipo no soportado' => fn () => [AccessKeyFactory::corrupt(8, '99'), 'tipo de comprobante'],
    'ambiente' => fn () => [AccessKeyFactory::corrupt(23, '9'), 'Ambiente inválido'],
    'verificador' => fn () => [AccessKeyFactory::withBrokenCheckDigit(), 'dígito verificador'],
]);

it('rechaza una fecha de emisión que no existe', function (): void {
    $key = AccessKeyFactory::make(date: '31022026');

    expect(fn () => new SriAccessKey($key))->toThrow(InvalidArgumentException::class, 'fecha de emisión');
});

it('calcula el dígito verificador con residuo 0 y 1', function (string $body, string $expected): void {
    expect(SriAccessKey::checkDigitFor($body))->toBe($expected);
})->with([
    // Suma ponderada 44 → resto 0 → verificador 0.
    'residuo 0' => ['1'.str_repeat('0', 41).'000002', '0'],
    // Suma ponderada 54 → 11 − 10 = 1.
    'residuo 1' => ['1'.str_repeat('0', 41).'000007', '1'],
    // Suma ponderada 53 → 11 − 9 = 2.
    'residuo 2' => ['1'.str_repeat('0', 41).'000001', '2'],
]);

it('aplica los pesos 2..7 de derecha a izquierda', function (): void {
    // 48 unos acumulan 8 ciclos de 27 → 216; 216 % 11 = 7 → verificador 4.
    expect(SriAccessKey::checkDigitFor(str_repeat('1', 48)))->toBe('4');
});

it('rechaza calcular el verificador con menos de 48 dígitos', function (): void {
    expect(fn () => SriAccessKey::checkDigitFor('123'))->toThrow(InvalidArgumentException::class);
});
