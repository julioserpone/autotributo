<?php

declare(strict_types=1);

namespace App\Domain\Shared\Enums;

/**
 * Tipo de contribuyente derivado del tercer dígito del RUC.
 *
 * - `0` a `5`: persona natural (cédula + establecimiento).
 * - `6`: entidad del sector público.
 * - `9`: persona jurídica / sociedad privada.
 */
enum RucType: string
{
    case Natural = 'natural';
    case Public = 'public';
    case Private = 'private';

    public function label(): string
    {
        return match ($this) {
            self::Natural => 'Persona natural',
            self::Public => 'Sector público',
            self::Private => 'Persona jurídica',
        };
    }
}
