<?php

declare(strict_types=1);

namespace App\Domain\Classification\Services;

use App\Domain\Classification\Enums\MatchType;
use App\Domain\Classification\Models\ClassificationRule;
use App\Domain\Invoicing\Models\InvoiceItem;
use Illuminate\Support\Str;

/**
 * Evalúa reglas contra un ítem de comprobante.
 *
 * La comparación de texto usa la descripción normalizada (sin acentos,
 * mayúsculas y con espacios colapsados) para que "PAN  INTEGRAL" y
 * "pan integral" caigan en la misma regla. El orden de las reglas lo define
 * `ClassificationRuleRepository`.
 */
final class ClassificationRuleMatcher
{
    /**
     * @param  iterable<int, ClassificationRule>  $rules
     */
    public function firstMatch(iterable $rules, InvoiceItem $item, ?string $issuerRuc): ?ClassificationRule
    {
        foreach ($rules as $rule) {
            if ($this->matches($rule, $item, $issuerRuc)) {
                return $rule;
            }
        }

        return null;
    }

    public function matches(ClassificationRule $rule, InvoiceItem $item, ?string $issuerRuc): bool
    {
        $pattern = trim((string) $rule->pattern);

        return match ($rule->match_type) {
            MatchType::Contains => $this->contains((string) $item->description, $pattern),
            MatchType::StartsWith => $this->startsWith((string) $item->description, $pattern),
            MatchType::Exact => $this->normalize((string) $item->description) === $this->normalize($pattern),
            MatchType::Regex => $this->regexMatches($pattern, (string) $item->description),
            MatchType::ProviderRuc => $issuerRuc !== null && trim($issuerRuc) === $pattern,
            MatchType::ItemCode => $this->itemCodeMatches($item, $pattern),
        };
    }

    /**
     * Normaliza una descripción o patrón para compararlos.
     */
    public function normalize(string $value): string
    {
        $ascii = Str::ascii($value);
        $collapsed = preg_replace('/\s+/u', ' ', $ascii) ?? $ascii;

        return Str::upper(trim($collapsed));
    }

    public static function isValidRegex(string $pattern): bool
    {
        if ($pattern === '') {
            return false;
        }

        // `@preg_match` deja el aviso en el manejador de errores de PHPUnit;
        // silenciarlo con un manejador propio evita ruido en la suite sin
        // ocultar errores reales (solo cubre la comprobación de compilación).
        set_error_handler(static fn (): bool => true);

        try {
            return preg_match($pattern, '') !== false;
        } finally {
            restore_error_handler();
        }
    }

    private function contains(string $description, string $pattern): bool
    {
        $needle = $this->normalize($pattern);

        return $needle !== '' && str_contains($this->normalize($description), $needle);
    }

    private function startsWith(string $description, string $pattern): bool
    {
        $needle = $this->normalize($pattern);

        return $needle !== '' && str_starts_with($this->normalize($description), $needle);
    }

    private function regexMatches(string $pattern, string $description): bool
    {
        if (! self::isValidRegex($pattern)) {
            return false;
        }

        return @preg_match($pattern, $description) === 1;
    }

    private function itemCodeMatches(InvoiceItem $item, string $pattern): bool
    {
        $expected = Str::upper(trim($pattern));

        if ($expected === '') {
            return false;
        }

        foreach ([$item->main_code, $item->auxiliary_code] as $code) {
            if ($code !== null && Str::upper(trim((string) $code)) === $expected) {
                return true;
            }
        }

        return false;
    }
}
