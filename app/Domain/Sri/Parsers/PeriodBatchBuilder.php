<?php

declare(strict_types=1);

namespace App\Domain\Sri\Parsers;

use App\Domain\Sri\Data\SriAccessKey;
use App\Domain\Sri\Data\SriPeriodBatch;
use App\Domain\Sri\Data\SriTextReportParseResult;
use App\Domain\Sri\Enums\SriDocumentType;

/**
 * Cruza el TXT de claves con el JSON de claves del mismo período y tipo.
 *
 * `ReconcileSriRun` (F2-05) ya comprobó que ambos archivos existen y que el
 * JSON corresponde al manifiesto. Aquí se valida lo que el JSON no dice: que
 * cada línea del TXT sea una clave bien formada, del tipo y período esperados,
 * emitida por el RUC del run, y que las dos listas coincidan.
 *
 * Las diferencias no lanzan: se exponen en el lote para que el run quede
 * `partial` con motivo (F2-10) en lugar de importar un paquete incompleto.
 */
final readonly class PeriodBatchBuilder
{
    public function __construct(private AccessKeyListParser $parser = new AccessKeyListParser) {}

    /**
     * @param  list<string>  $listedKeys  Claves declaradas en el JSON de claves del robot
     */
    public function build(
        string $contents,
        string $period,
        SriDocumentType $documentType,
        string $ruc,
        array $listedKeys,
    ): SriPeriodBatch {
        $parsed = $this->parser->parse($contents, $period);
        $expectedPeriod = substr($period, 0, 7);

        /** @var list<SriAccessKey> $valid */
        $valid = array_values(array_filter(
            $parsed->accessKeys,
            static fn ($key) => $key->documentType === $documentType
                && $key->period() === $expectedPeriod
                && $key->issuerRuc === $ruc,
        ));

        $invalid = $this->invalidLines($parsed, $documentType, $expectedPeriod, $ruc);

        $listed = array_values(array_unique(array_map(
            static fn (mixed $key): string => trim((string) $key),
            $listedKeys,
        )));

        $validValues = array_map(static fn ($key) => $key->value, $valid);

        return new SriPeriodBatch(
            period: $period,
            documentType: $documentType,
            accessKeys: $valid,
            invalidAccessKeys: $invalid,
            missingAccessKeys: array_values(array_diff($listed, $validValues)),
            unlistedAccessKeys: array_values(array_diff($validValues, $listed)),
            totalLines: $parsed->totalLines,
            duplicateLines: $parsed->duplicateLines,
        );
    }

    /**
     * @return list<string>
     */
    private function invalidLines(
        SriTextReportParseResult $parsed,
        SriDocumentType $documentType,
        string $period,
        string $ruc,
    ): array {
        $invalid = array_map(
            static fn (array $line): string => (string) $line['value'],
            $parsed->invalidLines,
        );

        foreach ($parsed->accessKeys as $key) {
            $reason = match (true) {
                $key->documentType !== $documentType => 'tipo',
                $key->period() !== $period => 'período',
                $key->issuerRuc !== $ruc => 'RUC',
                default => null,
            };

            if ($reason !== null) {
                $invalid[] = $key->value;
            }
        }

        return $invalid;
    }
}
