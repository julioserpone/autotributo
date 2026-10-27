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
 * Formato observado: una clave de acceso por línea, sin cabecera ni separador
 * adicional. Se toleran BOM UTF-8, fin de línea CRLF, espacios alrededor y
 * líneas vacías, porque el robot escribe con el texto que entrega el portal.
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

        $keys = [];
        $invalid = [];
        $seen = [];
        $empty = 0;
        $duplicates = 0;

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

        return new SriTextReportParseResult(
            period: $period,
            accessKeys: $keys,
            invalidLines: $invalid,
            totalLines: count($lines),
            emptyLines: $empty,
            duplicateLines: $duplicates,
        );
    }

    private function stripBom(string $contents): string
    {
        return str_starts_with($contents, "\xEF\xBB\xBF") ? substr($contents, 3) : $contents;
    }
}
