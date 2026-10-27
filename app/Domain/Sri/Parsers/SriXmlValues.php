<?php

declare(strict_types=1);

namespace App\Domain\Sri\Parsers;

use App\Domain\Sri\Exceptions\SriXmlException;

/**
 * Conversiones estables entre el texto del XML y el modelo de datos.
 *
 * Los montos del SRI vienen como decimales (`12.34`). Convertirlos con `float`
 * introduce errores de redondeo en sumas fiscales, así que se hace aritmética
 * de cadenas y se redondea al centavo (medio hacia arriba).
 */
final class SriXmlValues
{
    /**
     * `"12.35"` → `1235`. Acepta coma o punto decimal y espacios.
     */
    public static function cents(string $value, string $field = 'valor'): int
    {
        $normalized = str_replace([',', ' '], ['.', ''], trim($value));

        if (preg_match('/^(-)?(\d*)(?:\.(\d{1,6}))?$/', $normalized, $matches) !== 1) {
            throw SriXmlException::invalidField($field);
        }

        $negative = $matches[1] === '-';
        $units = $matches[2] === '' ? '0' : $matches[2];
        $decimals = str_pad($matches[3] ?? '', 7, '0');

        // Se conservan 7 decimales para poder redondear el sexto correctamente.
        $cents = (int) $units * 100 + (int) substr($decimals, 0, 2);
        $roundUp = (int) ($decimals[2] ?? '0') >= 5;

        if ($roundUp) {
            $cents++;
        }

        return $negative ? -$cents : $cents;
    }

    /**
     * Texto de clasificación: sin acentos, mayúsculas y espacios colapsados.
     */
    public static function normalizeDescription(string $description): string
    {
        $transliterated = self::withoutAccents($description);
        $collapsed = preg_replace('/\s+/u', ' ', trim($transliterated)) ?? '';

        return mb_strtoupper($collapsed, 'UTF-8');
    }

    /**
     * Fecha `dd/mm/yyyy` del XML → `Y-m-d`.
     */
    public static function date(string $value, string $field = 'fecha'): string
    {
        $value = trim($value);

        foreach (['d/m/Y', 'd-m-Y', 'Y-m-d'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $value);

            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed->format('Y-m-d');
            }
        }

        throw SriXmlException::invalidField($field);
    }

    /**
     * Fecha y hora `dd/mm/yyyy hh:mm:ss` → `Y-m-d H:i:s`.
     */
    public static function dateTime(string $value, string $field = 'fecha'): string
    {
        $value = trim($value);

        foreach (['d/m/Y H:i:s', 'd/m/Y H:i', 'Y-m-d H:i:s'] as $format) {
            $parsed = \DateTimeImmutable::createFromFormat('!'.$format, $value);

            if ($parsed !== false && $parsed->format($format) === $value) {
                return $parsed->format('Y-m-d H:i:s');
            }
        }

        throw SriXmlException::invalidField($field);
    }

    private static function withoutAccents(string $value): string
    {
        $map = [
            'á' => 'a',
            'é' => 'e',
            'í' => 'i',
            'ó' => 'o',
            'ú' => 'u',
            'à' => 'a',
            'è' => 'e',
            'ì' => 'i',
            'ò' => 'o',
            'ù' => 'u',
            'ä' => 'a',
            'ë' => 'e',
            'ï' => 'i',
            'ö' => 'o',
            'ü' => 'u',
            'Á' => 'A',
            'É' => 'E',
            'Í' => 'I',
            'Ó' => 'O',
            'Ú' => 'U',
            'À' => 'A',
            'È' => 'E',
            'Ì' => 'I',
            'Ò' => 'O',
            'Ù' => 'U',
            'Ä' => 'A',
            'Ë' => 'E',
            'Ï' => 'I',
            'Ö' => 'O',
            'Ü' => 'U',
            'ñ' => 'N',
            'Ñ' => 'N',
        ];

        return strtr($value, $map);
    }
}
