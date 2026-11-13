<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Enums;

/**
 * Naturaleza del valor que se registra en un casillero de formulario.
 */
enum FormLineDataType: string
{
    case Amount = 'amount';
    case Count = 'count';
    case Rate = 'rate';
}
