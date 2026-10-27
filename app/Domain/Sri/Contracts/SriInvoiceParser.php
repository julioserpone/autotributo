<?php

declare(strict_types=1);

namespace App\Domain\Sri\Contracts;

use App\Domain\Sri\Data\SriInvoiceData;
use App\Domain\Sri\Exceptions\SriXmlException;

/**
 * Interpretación del XML autorizado de un comprobante.
 *
 * Es un puerto y no una dependencia directa del paquete a propósito: la
 * lectura del XML del SRI pertenece al dominio del formato de comprobantes y
 * concretamente a `julioserpone/sri-comprobantes-php` (generar, validar y
 * consultar comprobantes). Hoy el paquete consulta y devuelve el XML como
 * texto pero todavía no lo interpreta, así que el adaptador por defecto es la
 * implementación local (`SriXmlParser`). Cuando el paquete publique el
 * parser, se sustituye el adaptador en `SriServiceProvider` sin tocar
 * acciones, jobs ni tests. Ver `docs/11-next-stage-plan.md` §6.
 */
interface SriInvoiceParser
{
    /**
     * @throws SriXmlException
     */
    public function parse(string $xml): SriInvoiceData;
}
