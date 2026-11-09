<?php

declare(strict_types=1);

namespace App\Domain\Companies\Exceptions;

use RuntimeException;

/**
 * No se puede iniciar una sincronización sin credencial activa del portal.
 *
 * Se mapea a `409 Conflict` en la capa HTTP: la petición es válida, pero el
 * recurso (la empresa) no está listo para operar.
 */
final class SriCredentialsMissing extends RuntimeException
{
    public static function forCompany(string $companyId): self
    {
        return new self("La empresa {$companyId} no tiene credenciales SRI activas.");
    }
}
