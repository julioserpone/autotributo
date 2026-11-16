<?php

declare(strict_types=1);

namespace App\Domain\Classification\Enums;

/**
 * Decisión de una persona sobre una clasificación sugerida.
 *
 * Aceptar confirma la categoría (la sugerida o una elegida); rechazar deja el
 * ítem sin categoría y pendiente, para que una regla futura lo vuelva a
 * intentar.
 */
enum ReviewDecision: string
{
    case Accept = 'accept';
    case Reject = 'reject';

    public function label(): string
    {
        return match ($this) {
            self::Accept => 'Aceptar',
            self::Reject => 'Rechazar',
        };
    }
}
