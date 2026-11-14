<?php

declare(strict_types=1);

namespace App\Domain\Classification\Validation;

use App\Domain\Classification\Enums\MatchType;
use App\Domain\Classification\Services\ClassificationRuleMatcher;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Valida que una regla `regex` compile antes de guardarla.
 *
 * Se compila con `preg_match` sobre una cadena vacía; así se evita guardar un
 * patrón que rompería el motor en la siguiente importación.
 */
final readonly class ValidMatchPattern implements ValidationRule
{
    public function __construct(private ?string $matchType) {}

    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if ($this->matchType !== MatchType::Regex->value) {
            return;
        }

        if (! is_string($value) || ! ClassificationRuleMatcher::isValidRegex($value)) {
            $fail('La expresión regular no es válida.');
        }
    }
}
