<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Actions\ComputePeriodAggregates;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Sri\Contracts\SriFiscalDocumentParser;
use App\Domain\Sri\Data\SriImportResult;
use App\Domain\Sri\Exceptions\SriXmlException;
use Throwable;

/**
 * Importa un XML autorizado subido manualmente por un usuario.
 *
 * Es la contingencia operativa de US-14: no crea `SriSyncRun` ni encola un
 * job, solo interpreta el documento y lo persiste con `source=manual_upload`.
 * La acción recibe el XML ya leído para no acoplar el dominio a HTTP.
 */
final readonly class ImportManualSriFiscalDocument
{
    private const MAX_BYTES = 2_097_152;

    public function __construct(
        private SriFiscalDocumentParser $parser,
        private PersistSriFiscalDocument $persist,
        private ComputePeriodAggregates $compute,
    ) {}

    /**
     * @throws SriXmlException
     */
    public function handle(Company $company, string $xml): SriImportResult
    {
        if (trim($xml) === '') {
            throw SriXmlException::unreadable();
        }

        if (strlen($xml) > self::MAX_BYTES) {
            throw SriXmlException::tooLarge();
        }

        // Defensa en profundidad frente a XXE: el parser del paquete ya carga
        // sin entidades externas, pero un XML del SRI nunca necesita DTD.
        if (preg_match('/<!DOCTYPE|<!ENTITY/i', $xml) === 1) {
            throw SriXmlException::unsafe();
        }

        $data = $this->parser->parse($xml);

        $result = $this->persist->handle(
            $company,
            $data,
            $xml,
            null,
            FiscalDocument::SOURCE_MANUAL_UPLOAD,
        );

        try {
            $document = $result->fiscal_document;

            $this->compute->handle(
                $company,
                $document->issue_date->year,
                $document->issue_date->month,
            );
        } catch (Throwable $exception) {
            report($exception);
        }

        return $result;
    }
}
