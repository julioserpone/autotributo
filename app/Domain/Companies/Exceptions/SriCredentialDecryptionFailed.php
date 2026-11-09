<?php

declare(strict_types=1);

namespace App\Domain\Companies\Exceptions;

use RuntimeException;

/**
 * El blob no se puede descifrar: clave distinta, versión distinta o blob corrupto.
 *
 * El mensaje nunca incluye el blob ni la contraseña; solo el identificador del
 * tenant y la versión esperada, para poder diagnosticar rotaciones pendientes.
 */
final class SriCredentialDecryptionFailed extends RuntimeException
{
    public static function forCompany(int $companyId, int $expectedVersion, string $reason): self
    {
        return new self(
            "No fue posible descifrar la credencial SRI de la empresa {$companyId} "
            ."(clave v{$expectedVersion}): {$reason}",
        );
    }
}
