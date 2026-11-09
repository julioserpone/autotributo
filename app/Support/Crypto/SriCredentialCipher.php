<?php

declare(strict_types=1);

namespace App\Support\Crypto;

use RuntimeException;

/**
 * Cifra y descifra el secreto del portal SRI con una clave dedicada.
 *
 * Se usa `sodium_crypto_secretbox` (XSalsa20-Poly1305) sobre `SRI_CREDENTIALS_KEY`,
 * nunca `APP_KEY`: rotar la clave de la aplicación no debe invalidar las
 * credenciales del contribuyente ni obligar a volver a pedirlas.
 *
 * Formato del blob: `v1:{key_version}:{nonce base64}:{ciphertext base64}`.
 * El `key_version` permite rotar la clave sin reescribir todas las filas de
 * golpe; una fila cifrada con una versión distinta falla de forma explícita en
 * lugar de devolver basura.
 */
final readonly class SriCredentialCipher
{
    public const VERSION = 'v1';

    private const KEY_BYTES = SODIUM_CRYPTO_SECRETBOX_KEYBYTES;

    private const NONCE_BYTES = SODIUM_CRYPTO_SECRETBOX_NONCEBYTES;

    public function __construct(
        private string $key,
        private int $keyVersion = 1,
    ) {
        if (strlen($this->key) !== self::KEY_BYTES) {
            throw new RuntimeException('SRI_CREDENTIALS_KEY debe decodificar a 32 bytes.');
        }

        if ($this->keyVersion < 1) {
            throw new RuntimeException('La versión de la clave SRI debe ser positiva.');
        }
    }

    /**
     * Construye el cifrador desde la variable de entorno (base64 de 32 bytes).
     */
    public static function fromConfig(?string $encodedKey, int $keyVersion = 1): self
    {
        if ($encodedKey === null || trim($encodedKey) === '') {
            throw new RuntimeException('Falta SRI_CREDENTIALS_KEY en el entorno.');
        }

        $key = base64_decode(trim($encodedKey), true);

        if ($key === false) {
            throw new RuntimeException('SRI_CREDENTIALS_KEY no es base64 válido.');
        }

        return new self($key, $keyVersion);
    }

    public function encrypt(#[\SensitiveParameter] string $plaintext): string
    {
        if ($plaintext === '') {
            throw new RuntimeException('No se puede cifrar un secreto vacío.');
        }

        $nonce = random_bytes(self::NONCE_BYTES);
        $ciphertext = sodium_crypto_secretbox($plaintext, $nonce, $this->key);

        return implode(':', [
            self::VERSION,
            (string) $this->keyVersion,
            base64_encode($nonce),
            base64_encode($ciphertext),
        ]);
    }

    public function decrypt(string $blob): string
    {
        $parts = explode(':', $blob);

        if (count($parts) !== 4 || $parts[0] !== self::VERSION) {
            throw new RuntimeException('El blob de credencial SRI no tiene el formato esperado.');
        }

        if ((int) $parts[1] !== $this->keyVersion) {
            throw new RuntimeException(
                "La credencial SRI está cifrada con la versión de clave {$parts[1]}, no con {$this->keyVersion}.",
            );
        }

        $nonce = base64_decode($parts[2], true);
        $ciphertext = base64_decode($parts[3], true);

        if ($nonce === false || $ciphertext === false
            || strlen($nonce) !== self::NONCE_BYTES
            || strlen($ciphertext) < SODIUM_CRYPTO_SECRETBOX_MACBYTES) {
            throw new RuntimeException('El blob de credencial SRI está corrupto.');
        }

        $plaintext = sodium_crypto_secretbox_open($ciphertext, $nonce, $this->key);

        if ($plaintext === false) {
            throw new RuntimeException('No fue posible descifrar la credencial SRI.');
        }

        return $plaintext;
    }

    public function keyVersion(): int
    {
        return $this->keyVersion;
    }
}
