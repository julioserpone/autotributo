<?php

declare(strict_types=1);

namespace App\Integrations\Sri;

use App\Domain\Sri\Contracts\SriInvoiceParser;
use App\Domain\Sri\Data\SriInvoiceData;
use App\Domain\Sri\Parsers\SriXmlParser;

/**
 * Adaptador del parser de comprobantes.
 *
 * Encapsula la implementación concreta para que el dominio solo dependa de
 * `SriInvoiceParser`. El día que `julioserpone/sri-comprobantes-php` exponga
 * la lectura de comprobantes recibidos, esta clase pasa a delegar en el
 * paquete y el resto de la aplicación no cambia.
 */
final readonly class PackageSriInvoiceParser implements SriInvoiceParser
{
    public function __construct(private SriXmlParser $fallback) {}

    public function parse(string $xml): SriInvoiceData
    {
        return $this->fallback->parse($xml);
    }
}
