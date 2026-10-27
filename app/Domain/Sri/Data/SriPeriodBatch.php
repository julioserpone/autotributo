<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

use App\Domain\Sri\Enums\SriDocumentType;

/**
 * Lote de claves de acceso de un período y un tipo de comprobante.
 *
 * Es la entrada del parser XML: cada clave del TXT del robot que también
 * aparece en su JSON de claves. Las claves inválidas del TXT se conservan
 * aparte para reportarlas por archivo y no detener el run.
 */
final readonly class SriPeriodBatch
{
    /**
     * @param  list<SriAccessKey>  $accessKeys  Claves válidas y presentes en el JSON de claves
     * @param  list<string>  $invalidAccessKeys  Líneas del TXT que no son claves válidas
     * @param  list<string>  $missingAccessKeys  Claves del JSON que no están en el TXT
     * @param  list<string>  $unlistedAccessKeys  Claves válidas del TXT que no están en el JSON
     */
    public function __construct(
        public string $period,
        public SriDocumentType $documentType,
        public array $accessKeys,
        public array $invalidAccessKeys = [],
        public array $missingAccessKeys = [],
        public array $unlistedAccessKeys = [],
        public int $totalLines = 0,
        public int $duplicateLines = 0,
    ) {}

    /**
     * @return list<string>
     */
    public function values(): array
    {
        return array_map(
            static fn (SriAccessKey $key): string => $key->value,
            $this->accessKeys,
        );
    }

    public function count(): int
    {
        return count($this->accessKeys);
    }

    public function isEmpty(): bool
    {
        return $this->accessKeys === [];
    }

    /**
     * El lote se puede procesar cuando hay al menos una clave válida y no hay
     * inconsistencias entre el TXT y el JSON de claves del robot.
     */
    public function isConsistent(): bool
    {
        return $this->missingAccessKeys === [] && $this->unlistedAccessKeys === [];
    }
}
