<?php

declare(strict_types=1);

namespace App\Domain\Companies\Enums;

/**
 * Régimen tributario declarado para una empresa.
 *
 * El régimen determina las obligaciones fiscales futuras, pero no altera
 * declaraciones ya generadas. La derivación de obligaciones se incorpora en
 * F3-01/F3-02.
 */
enum TaxRegime: string
{
    case General = 'general';
    case RimpeEmprendedor = 'rimpe_emprendedor';
    case RimpeNegocioPopular = 'rimpe_negocio_popular';
    case PersonaNatural = 'persona_natural';
    case Sociedad = 'sociedad';

    public function label(): string
    {
        return match ($this) {
            self::General => 'Régimen general',
            self::RimpeEmprendedor => 'RIMPE emprendedor',
            self::RimpeNegocioPopular => 'RIMPE negocio popular',
            self::PersonaNatural => 'Persona natural',
            self::Sociedad => 'Sociedad',
        };
    }
}
