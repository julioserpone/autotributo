<?php

declare(strict_types=1);

namespace App\Domain\Companies\Exceptions;

use RuntimeException;

/**
 * La credencial existe pero no puede usarse en este momento.
 *
 * Casos: descifrado imposible por una rotación de clave pendiente o estado
 * `invalid`/`locked` tras varios fallos de autenticación. Se mapea a `409`:
 * la operación es correcta pero la empresa necesita intervención manual.
 */
final class SriCredentialUnavailable extends RuntimeException
{
    public static function notDecryptable(string $companyId): self
    {
        return new self(
            "La credencial SRI de la empresa {$companyId} no se puede descifrar; "
            .'revise la clave configurada o vuelva a cargar la contraseña.',
        );
    }

    public static function invalidStatus(string $companyId, string $status): self
    {
        return new self(
            "La credencial SRI de la empresa {$companyId} está en estado {$status}; "
            .'actualícela antes de sincronizar.',
        );
    }
}
