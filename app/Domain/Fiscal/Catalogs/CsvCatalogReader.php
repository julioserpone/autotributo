<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Catalogs;

use RuntimeException;

/**
 * Lectura de un CSV oficial de catálogos del SRI.
 *
 * Formato esperado: primera fila de cabeceras y separador `,` o `;` (el SRI
 * publica ambos). Las cabeceras se normalizan a `snake_case` para que
 * `iva_porcentaje` y `IVA Porcentaje` lleguen igual al modelo; las celdas
 * vacías se convierten en `null` para no crear cadenas vacías que rompan los
 * casts (`valid_to`, `legal_basis`, etc.).
 *
 * El archivo no se interpreta por catálogo: la forma de las columnas la valida
 * `SyncFiscalCatalogs` antes de escribir.
 */
final readonly class CsvCatalogReader
{
    private const DELIMITERS = [',', ';'];

    /**
     * @return list<array<string, string|null>>
     */
    public function read(string $contents): array
    {
        $contents = $this->stripBom($contents);

        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

        if ($lines === []) {
            throw new RuntimeException('El CSV está vacío.');
        }

        $delimiter = $this->detectDelimiter($lines[0]);
        $headers = $this->normalizeHeaders($this->split($lines[0], $delimiter));

        $rows = [];

        foreach (array_slice($lines, 1) as $index => $line) {
            $values = $this->split($line, $delimiter);

            if (count($values) !== count($headers)) {
                throw new RuntimeException(sprintf(
                    'La fila %d tiene %d columnas y la cabecera %d.',
                    $index + 2,
                    count($values),
                    count($headers),
                ));
            }

            /** @var array<string, string|null> $row */
            $row = [];

            foreach ($headers as $position => $header) {
                $value = trim($values[$position]);
                $row[$header] = $value === '' ? null : $value;
            }

            $rows[] = $row;
        }

        return $rows;
    }

    private function stripBom(string $contents): string
    {
        return str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
    }

    private function detectDelimiter(string $headerLine): string
    {
        foreach (self::DELIMITERS as $delimiter) {
            if (substr_count($headerLine, $delimiter) > 0) {
                return $delimiter;
            }
        }

        throw new RuntimeException('No se reconoce el separador del CSV (se esperaba `,` o `;`).');
    }

    /**
     * @return list<string>
     */
    private function split(string $line, string $delimiter): array
    {
        /** @var list<string> $values */
        $values = str_getcsv($line, $delimiter, '"', '\\');

        return $values;
    }

    /**
     * @param  list<string>  $headers
     * @return list<string>
     */
    private function normalizeHeaders(array $headers): array
    {
        /** @var list<string> $normalized */
        $normalized = [];

        foreach ($headers as $header) {
            $key = trim($this->stripAccents($header));
            $key = (string) preg_replace('/[^A-Za-z0-9]+/', '_', $key);
            $key = strtolower(trim($key, '_'));

            if ($key === '') {
                throw new RuntimeException('El CSV tiene una columna sin nombre en la cabecera.');
            }

            $normalized[] = $key;
        }

        return $normalized;
    }

    private function stripAccents(string $value): string
    {
        return strtr($value, [
            'á' => 'a', 'é' => 'e', 'í' => 'i', 'ó' => 'o', 'ú' => 'u',
            'Á' => 'A', 'É' => 'E', 'Í' => 'I', 'Ó' => 'O', 'Ú' => 'U',
            'ñ' => 'n', 'Ñ' => 'N',
        ]);
    }
}
