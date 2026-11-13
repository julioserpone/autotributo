<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Enums;

/**
 * Impuesto al que aplica una retención en la fuente.
 */
enum RetentionTaxType: string
{
    case IncomeTax = 'IR';
    case Vat = 'IVA';

    public function label(): string
    {
        return match ($this) {
            self::IncomeTax => 'Impuesto a la Renta',
            self::Vat => 'Impuesto al Valor Agregado',
        };
    }
}
