<?php

declare(strict_types=1);

namespace App\Domain\Sri\Parsers;

use App\Domain\Sri\Data\SriAccessKey;
use App\Domain\Sri\Data\SriTextReportParseResult;
use InvalidArgumentException;
use Throwable;

/**
 * Parser del TXT de claves que publica `sri-txt-robot`.
 *
 * Formatos soportados:
 * - Histórico: una clave de acceso por línea, sin cabecera ni separador.
 * - Reporte tabular (TSV): encabezado con la columna `CLAVE_ACCESO`; se
 *   extrae esa columna de cada fila. Es el formato que publica el robot real.
 *
 * Se toleran BOM UTF-8, fin de línea CRLF, espacios alrededor y líneas vacías.
 *
 * Las líneas que no son claves válidas se devuelven con motivo en lugar de
 * lanzar: un TXT parcial sigue siendo útil y el run debe poder reportarse por
 * archivo (F2-10).
 */
final readonly class AccessKeyListParser
{
    public function parse(string $contents, string $period): SriTextReportParseResult
    {
        if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
            throw new InvalidArgumentException("Período inválido para el TXT del robot: {$period}.");
        }

        $contents = $this->stripBom($contents);
        $lines = preg_split('/\r\n|\r|\n/', $contents) ?: [];

        $columnIndex = $this->accessKeyColumnIndex($lines);

        $keys = [];
        $invalid = [];
        $seen = [];
        $empty = 0;
        $duplicates = 0;

        if ($columnIndex !== null) {
            // Se descarta la fila de encabezado.
            $dataLines = array_slice($lines, 1);
            $totalLines = count($dataLines);

            foreach (array_values($dataLines) as $offset => $line) {
                $columns = explode("\t", $line);
                $value = trim($columns[$columnIndex] ?? '');

                if ($value === '') {
                    $empty++;

                    continue;
                }

                try {
                    $key = new SriAccessKey($value);
                } catch (Throwable $exception) {
                    $invalid[] = [
                        'line' => $offset + 2,
                        'value' => $value,
                        'reason' => $exception->getMessage(),
                    ];

                    continue;
                }

                if (isset($seen[$key->value])) {
                    $duplicates++;

                    continue;
                }

                $seen[$key->value] = true;
                $keys[] = $key;
            }
        } else {
            $totalLines = count($lines);

            foreach ($lines as $index => $line) {
                $value = trim($line);

                if ($value === '') {
                    $empty++;

                    continue;
                }

                try {
                    $key = new SriAccessKey($value);
                } catch (Throwable $exception) {
                    $invalid[] = [
                        'line' => $index + 1,
                        'value' => $value,
                        'reason' => $exception->getMessage(),
                    ];

                    continue;
                }

                if (isset($seen[$key->value])) {
                    $duplicates++;

                    continue;
                }

                $seen[$key->value] = true;
                $keys[] = $key;
            }
        }

        return new SriTextReportParseResult(
            period: $period,
            accessKeys: $keys,
            invalidLines: $invalid,
            totalLines: $totalLines,
            emptyLines: $empty,
            duplicateLines: $duplicates,
        );
    }

    /**
     * Devuelve el índice (0-based) de la columna `CLAVE_ACCESO` si la primera
     * línea es un encabezado tabular que la contiene; `null` en caso contrario.
     *
     * @param  list<string>  $lines
     */
    private function accessKeyColumnIndex(array $lines): ?int
    {
        if ($lines === []) {
            return null;
        }

        $header = explode("\t", (string) $lines[0]);
        $header = array_map(static fn (string $value): string => trim($value), $header);

        foreach ($header as $index => $name) {
            if (strcasecmp($name, 'CLAVE_ACCESO') === 0) {
                return $index;
            }
        }

        return null;
    }

    private function stripBom(string $contents): string
    {
        return str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
    }
}
