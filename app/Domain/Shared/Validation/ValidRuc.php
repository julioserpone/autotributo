<?php

declare(strict_types=1);

namespace App\Domain\Shared\Validation;

use App\Domain\Shared\Values\Ruc;
use Closure;
use Illuminate\Contracts\Validation\ValidationRule;

/**
 * Regla de validación para el RUC ecuatoriano.
 *
 * Delega en el value object `Ruc` para que la regla de negocio viva en el
 * dominio y la validación HTTP solo la invoque.
 */
final class ValidRuc implements ValidationRule
{
    public function validate(string $attribute, mixed $value, Closure $fail): void
    {
        if (! is_string($value) || Ruc::tryFrom($value) === null) {
            $fail('El RUC no es válido.');
        }
    }
}
