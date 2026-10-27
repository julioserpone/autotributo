<?php

declare(strict_types=1);

namespace Tests\Support\Sri;

use App\Domain\Sri\Data\SriAccessKey;

/**
 * Genera claves de acceso coherentes para fixtures y pruebas.
 *
 * El dígito verificador se calcula con el mismo módulo 11 del dominio: aquí se
 * usa para construir claves válidas, no para validar el algoritmo (eso lo
 * prueban los casos de `checkDigitFor` en `SriAccessKeyTest`).
 */
final class AccessKeyFactory
{
    /**
     * Clave de 49 dígitos: fecha, tipo, RUC, ambiente, serie, secuencial,
     * código numérico, verificador y tipo de emisión.
     */
    public static function make(
        string $date = '15092026',
        string $type = '01',
        string $ruc = '1758871873001',
        string $environment = '2',
        string $establishment = '001',
        string $emissionPoint = '001',
        string $sequential = '000000123',
        string $numericCode = '87654321',
        string $emissionType = '1',
    ): string {
        $body = $date.$type.$ruc.$environment.$establishment.$emissionPoint.$sequential.$numericCode.$emissionType;

        return $body.SriAccessKey::checkDigitFor($body);
    }

    /**
     * Clave con un atributo alterado desde `$offset` y el verificador
     * recalculado, para probar la validación estructural y no la firma.
     */
    public static function corrupt(int $offset, string $replacement): string
    {
        $key = self::make();
        $body = substr($key, 0, $offset).$replacement.substr($key, $offset + strlen($replacement));

        return substr($body, 0, 48).SriAccessKey::checkDigitFor($body);
    }

    /**
     * Clave válida con el último dígito alterado: falla solo el verificador.
     */
    public static function withBrokenCheckDigit(): string
    {
        $key = self::make();

        return substr($key, 0, 48).(substr($key, 48, 1) === '9' ? '8' : '9');
    }
}
