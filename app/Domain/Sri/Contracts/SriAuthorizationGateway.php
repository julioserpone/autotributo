<?php

declare(strict_types=1);

namespace App\Domain\Sri\Contracts;

use App\Domain\Sri\Data\AuthorizationQuery;
use App\Domain\Sri\Data\AuthorizationResult;

/**
 * Puerto de consulta del XML autorizado de un comprobante.
 *
 * La implementación de producción usa el paquete
 * `julioserpone/sri-comprobantes-php`; las pruebas usan un doble determinista.
 */
interface SriAuthorizationGateway
{
    public function query(AuthorizationQuery $query): AuthorizationResult;
}
