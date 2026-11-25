<?php

declare(strict_types=1);

namespace App\Domain\Classification\Catalogs;

use RuntimeException;

/**
 * Lectura del CSV de categorías de gasto.
 *
 * Columnas obligatorias: `code`, `name`, `parent_code`, `is_personal_deductible`,
 * `is_business_deductible`, `icon`, `display_order`. `parent_code` vacío es una
 * categoría raíz. Las celdas vacías se convierten en `null`.
 */
final readonly class CsvExpenseCategoryReader
{
    /**
     * @return list<array<string, mixed>>
     */
    public function read(string $contents): array
    {
        $contents = str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;

        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];
        $lines = array_values(array_filter($lines, static fn (string $line): bool => trim($line) !== ''));

        if ($lines === []) {
            throw new RuntimeException('El CSV está vacío.');
        }

        $delimiter = str_contains($lines[0], ';') ? ';' : ',';
        /** @var list<string> $rawHeaders */
        $rawHeaders = str_getcsv((string) array_shift($lines), $delimiter, '"', '\\');

        $headers = array_map(
            static fn (string $header): string => strtolower(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $header))),
            $rawHeaders,
        );

        $required = ['code', 'name', 'parent_code', 'is_personal_deductible', 'is_business_deductible', 'icon', 'display_order'];

        foreach ($required as $column) {
            if (! in_array($column, $headers, true)) {
                throw new RuntimeException("El CSV no trae la columna obligatoria `{$column}`.");
            }
        }

        $rows = [];

        foreach ($lines as $index => $line) {
            /** @var list<string> $values */
            $values = str_getcsv($line, $delimiter, '"', '\\');

            if (count($values) !== count($headers)) {
                throw new RuntimeException(sprintf('La fila %d tiene %d columnas y la cabecera %d.', $index + 2, count($values), count($headers)));
            }

            /** @var array<string, mixed> $row */
            $row = [];

            foreach ($headers as $position => $header) {
                $value = trim($values[$position]);
                $row[$header] = $value === '' ? null : $value;
            }

            foreach (['code', 'name'] as $column) {
                if ($row[$column] === null) {
                    throw new RuntimeException(sprintf('La fila %d no trae `%s`.', $index + 2, $column));
                }
            }

            $rows[] = [
                'code' => (string) $row['code'],
                'name' => (string) $row['name'],
                'parent_code' => $row['parent_code'] === null ? null : (string) $row['parent_code'],
                'is_personal_deductible' => in_array((string) $row['is_personal_deductible'], ['1', 'true', 'si', 'sí'], true),
                'is_business_deductible' => in_array((string) $row['is_business_deductible'], ['1', 'true', 'si', 'sí'], true),
                'icon' => $row['icon'] === null ? null : (string) $row['icon'],
                'display_order' => (int) ($row['display_order'] ?? 0),
                'valid_from' => isset($row['valid_from']) ? (string) $row['valid_from'] : null,
            ];
        }

        return $rows;
    }
}
