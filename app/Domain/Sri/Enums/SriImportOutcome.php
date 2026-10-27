<?php

declare(strict_types=1);

namespace App\Domain\Sri\Enums;

/**
 * Resultado de persistir (o saltar) un comprobante.
 *
 * Distinguir el salto de la creación permite reportar `imported` y `skipped`
 * por separado y verificar que reenviar el mismo paquete no duplica datos.
 */
enum SriImportOutcome: string
{
    case Created = 'created';
    case Updated = 'updated';
    case Skipped = 'skipped';

    public function wasStored(): bool
    {
        return $this !== self::Skipped;
    }
}
