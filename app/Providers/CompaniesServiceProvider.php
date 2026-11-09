<?php

declare(strict_types=1);

namespace App\Providers;

use App\Support\Crypto\SriCredentialCipher;
use Illuminate\Contracts\Foundation\Application;
use Illuminate\Support\ServiceProvider;

/**
 * Cablea el cifrado de credenciales SRI con su clave dedicada.
 *
 * `SriCredentialCipher` se construye desde `SRI_CREDENTIALS_KEY` y falla al
 * resolver si la variable no existe o es inválida. En `testing` se genera una
 * clave efímera si no se configuró, para que la suite no dependa del `.env`.
 */
final class CompaniesServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(SriCredentialCipher::class, function (Application $app): SriCredentialCipher {
            $encoded = trim((string) config('sri.credentials.key', ''));

            // En testing la clave puede faltar o venir como relleno (p. ej.
            // `base64:AAAA...`): se genera una efímera para no acoplar la
            // suite al `.env` de cada máquina.
            $decoded = base64_decode($encoded, true);

            if ($app->environment('testing')
                && ($encoded === '' || $decoded === false || trim($decoded, "\0") === '')) {
                $encoded = base64_encode(random_bytes(SODIUM_CRYPTO_SECRETBOX_KEYBYTES));
            }

            return SriCredentialCipher::fromConfig($encoded, (int) config('sri.credentials.key_version', 1));
        });
    }
}
