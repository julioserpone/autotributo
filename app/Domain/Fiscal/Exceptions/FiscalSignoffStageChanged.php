<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Exceptions;

use RuntimeException;

final class FiscalSignoffStageChanged extends RuntimeException
{
    public static function staleSnapshot(): self
    {
        return new self('La etapa cambió desde que se cargó. Actualiza la página y vuelve a revisarla antes de aprobar.');
    }
}
