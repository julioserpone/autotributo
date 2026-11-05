<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

use App\Domain\Sri\Enums\SriDocumentType;

/**
 * Ubicación verificada de un XML dentro del bucket del robot.
 *
 * Solo existe cuando el período del run tenía componentes en S3: un manifiesto
 * válido del ambiente, del RUC y del período consultados, o un lote de claves
 * del mismo período. Así la lectura desde S3 nunca cruza períodos ni empresas,
 * aunque la carpeta `*-xml/` del robot solo esté separada por período.
 */
final readonly class SriXmlLocation
{
    public function __construct(
        public SriDocumentType $documentType,
        public string $period,
        public string $key,
        public string $checksum,
        public bool $fromManifest = false,
    ) {}
}
