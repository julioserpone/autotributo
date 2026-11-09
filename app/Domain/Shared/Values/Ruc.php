<?php

declare(strict_types=1);

namespace App\Domain\Shared\Values;

use App\Domain\Shared\Enums\RucType;
use InvalidArgumentException;

/**
 * Registro Único de Contribuyentes de Ecuador.
 *
 * Valida la estructura de 13 dígitos y el dígito verificador según el tipo
 * de contribuyente:
 *
 * - Persona natural: cédula (módulo 10) + código de establecimiento.
 * - Sector público: módulo 11 con coeficientes propios y establecimiento de
 *   cuatro dígitos.
 * - Persona jurídica: módulo 11 sobre el secuencial y código de
 *   establecimiento.
 *
 * El primer par de dígitos es la provincia (01–24 o 30 para residentes en el
 * exterior). No se normalizan separadores: el valor debe llegar con los 13
 * dígitos, porque un RUC con guiones o espacios indica un error de captura.
 */
final readonly class Ruc
{
    /** @var list<int> */
    private const MODULO_10_COEFFICIENTS = [2, 1, 2, 1, 2, 1, 2, 1, 2];

    /** @var list<int> */
    private const MODULO_11_PRIVATE_COEFFICIENTS = [4, 3, 2, 7, 6, 5, 4, 3, 2];

    /** @var list<int> */
    private const MODULO_11_PUBLIC_COEFFICIENTS = [3, 2, 7, 6, 5, 4, 3, 2];

    private const FOREIGN_RESIDENT_PROVINCE = 30;

    private function __construct(
        public string $value,
        public RucType $type,
    ) {}

    public static function tryFrom(string $value): ?self
    {
        $value = trim($value);

        if (preg_match('/^\d{13}$/', $value) !== 1) {
            return null;
        }

        if (! self::hasValidProvince($value)) {
            return null;
        }

        $thirdDigit = (int) $value[2];

        $type = match (true) {
            $thirdDigit <= 5 => RucType::Natural,
            $thirdDigit === 6 => RucType::Public,
            $thirdDigit === 9 => RucType::Private,
            default => null,
        };

        if (! $type instanceof RucType) {
            return null;
        }

        $valid = match ($type) {
            RucType::Natural => self::isValidNatural($value),
            RucType::Public => self::isValidPublic($value),
            RucType::Private => self::isValidPrivate($value),
        };

        return $valid ? new self($value, $type) : null;
    }

    public static function from(string $value): self
    {
        return self::tryFrom($value)
            ?? throw new InvalidArgumentException("RUC inválido: {$value}");
    }

    public function equals(self $other): bool
    {
        return $this->value === $other->value;
    }

    public function __toString(): string
    {
        return $this->value;
    }

    private static function hasValidProvince(string $value): bool
    {
        $province = (int) substr($value, 0, 2);

        return ($province >= 1 && $province <= 24)
            || $province === self::FOREIGN_RESIDENT_PROVINCE;
    }

    private static function isValidNatural(string $value): bool
    {
        if ((int) substr($value, 10, 3) < 1) {
            return false;
        }

        $sum = 0;

        for ($index = 0; $index < 9; $index++) {
            $product = (int) $value[$index] * self::MODULO_10_COEFFICIENTS[$index];
            $sum += $product >= 10 ? $product - 9 : $product;
        }

        $remainder = $sum % 10;
        $expected = $remainder === 0 ? 0 : 10 - $remainder;

        return $expected === (int) $value[9];
    }

    private static function isValidPublic(string $value): bool
    {
        if ((int) substr($value, 9, 4) < 1) {
            return false;
        }

        return self::matchesModulo11(
            substr($value, 0, 8),
            (int) $value[8],
            self::MODULO_11_PUBLIC_COEFFICIENTS,
        );
    }

    private static function isValidPrivate(string $value): bool
    {
        if ((int) substr($value, 10, 3) < 1) {
            return false;
        }

        // El SRI emite RUC de sociedades con secuencial de siete dígitos que
        // no llevan dígito verificador validable. En ese caso solo se valida
        // provincia, tipo y establecimiento.
        if (self::hasExtendedSequential($value)) {
            return true;
        }

        return self::matchesModulo11(
            substr($value, 0, 9),
            (int) $value[9],
            self::MODULO_11_PRIVATE_COEFFICIENTS,
        );
    }

    private static function hasExtendedSequential(string $value): bool
    {
        if ($value[3] === '0') {
            return false;
        }

        return (int) substr($value, 3, 7) >= 1_000_000;
    }

    /**
     * @param  list<int>  $coefficients
     */
    private static function matchesModulo11(
        string $digits,
        int $checkDigit,
        array $coefficients,
    ): bool {
        $sum = 0;

        foreach (str_split($digits) as $index => $digit) {
            $sum += (int) $digit * $coefficients[$index];
        }

        $remainder = $sum % 11;
        $expected = $remainder === 0 ? 0 : 11 - $remainder;

        return $expected === $checkDigit;
    }
}
