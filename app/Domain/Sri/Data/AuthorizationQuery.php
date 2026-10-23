<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

use InvalidArgumentException;

/**
 * Consulta de un comprobante por su clave de acceso.
 *
 * La validación completa del dígito verificador la hace el paquete
 * `julioserpone/sri-comprobantes-php`; aquí se valida la forma mínima para
 * evitar enviar basura al puerto de integración.
 */
final readonly class AuthorizationQuery
{
    public function __construct(public string $accessKey)
    {
        if (preg_match('/^\d{49}$/', $this->accessKey) !== 1) {
            throw new InvalidArgumentException('La clave de acceso debe contener exactamente 49 dígitos.');
        }
    }
}
