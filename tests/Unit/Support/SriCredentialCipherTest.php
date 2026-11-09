<?php

declare(strict_types=1);

use App\Support\Crypto\SriCredentialCipher;

function cipherKey(): string
{
    return random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES);
}

it('cifra y descifra con la misma clave', function (): void {
    $cipher = new SriCredentialCipher(cipherKey());

    $blob = $cipher->encrypt('clave-secreta');

    expect($blob)->toStartWith('v1:1:')
        ->and($blob)->not->toContain('clave-secreta')
        ->and($cipher->decrypt($blob))->toBe('clave-secreta');
});

it('produce blobs distintos para el mismo secreto (nonce aleatorio)', function (): void {
    $cipher = new SriCredentialCipher(cipherKey());

    expect($cipher->encrypt('misma'))->not->toBe($cipher->encrypt('misma'));
});

it('falla al descifrar con otra clave', function (): void {
    $writer = new SriCredentialCipher(cipherKey());
    $reader = new SriCredentialCipher(cipherKey());

    expect(fn () => $reader->decrypt($writer->encrypt('secreto')))
        ->toThrow(RuntimeException::class);
});

it('falla explícitamente cuando la versión de clave no coincide', function (): void {
    $key = cipherKey();
    $writer = new SriCredentialCipher($key, 1);
    $reader = new SriCredentialCipher($key, 2);

    expect(fn () => $reader->decrypt($writer->encrypt('secreto')))
        ->toThrow(RuntimeException::class, 'versión de clave 1');
});

it('rechaza blobs corruptos o con formato inesperado', function (string $blob): void {
    $cipher = new SriCredentialCipher(cipherKey());

    expect(fn () => $cipher->decrypt($blob))->toThrow(RuntimeException::class);
})->with([
    'vacío' => '',
    'sin versión' => 'clave',
    'versión desconocida' => 'v2:1:bm9uY2U=:Y2lwaGVy',
    'nonce inválido' => 'v1:1:bm9uOmU=:Y2lwaGVy',
    'ciphertext corto' => 'v1:1:'.base64_encode(random_bytes(24)).':Y2lw',
]);

it('valida la clave y la versión al construirse', function (): void {
    expect(fn () => new SriCredentialCipher('corta'))->toThrow(RuntimeException::class)
        ->and(fn () => new SriCredentialCipher(cipherKey(), 0))->toThrow(RuntimeException::class)
        ->and(fn () => SriCredentialCipher::fromConfig(null))->toThrow(RuntimeException::class)
        ->and(fn () => SriCredentialCipher::fromConfig('no-es-base64!!'))->toThrow(RuntimeException::class);
});

it('construye desde base64 configurado', function (): void {
    $encoded = base64_encode(cipherKey());

    $cipher = SriCredentialCipher::fromConfig($encoded, 3);

    expect($cipher->keyVersion())->toBe(3)
        ->and($cipher->decrypt($cipher->encrypt('ok')))->toBe('ok');
});

it('no cifra secretos vacíos', function (): void {
    $cipher = new SriCredentialCipher(cipherKey());

    expect(fn () => $cipher->encrypt(''))->toThrow(RuntimeException::class);
});
