<?php

declare(strict_types=1);

namespace App\Domain\Classification\Enums;

/**
 * Forma en que una regla compara su patrón con el ítem.
 *
 * `contains`, `starts_with`, `exact` y `regex` trabajan sobre la descripción
 * normalizada; `provider_ruc` compara el RUC del emisor y `item_code` el
 * código principal o auxiliar del ítem.
 */
enum MatchType: string
{
    case Contains = 'contains';
    case StartsWith = 'starts_with';
    case Exact = 'exact';
    case Regex = 'regex';
    case ProviderRuc = 'provider_ruc';
    case ItemCode = 'item_code';

    public function label(): string
    {
        return match ($this) {
            self::Contains => 'La descripción contiene',
            self::StartsWith => 'La descripción empieza con',
            self::Exact => 'La descripción es exactamente',
            self::Regex => 'La descripción coincide con la expresión regular',
            self::ProviderRuc => 'El RUC del proveedor es',
            self::ItemCode => 'El código del ítem es',
        };
    }

    public function usesDescription(): bool
    {
        return in_array($this, [
            self::Contains,
            self::StartsWith,
            self::Exact,
            self::Regex,
        ], true);
    }

    public function isRegex(): bool
    {
        return $this === self::Regex;
    }
}
