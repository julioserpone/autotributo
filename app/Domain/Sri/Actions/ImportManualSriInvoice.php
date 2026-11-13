<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Companies\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Sri\Contracts\SriInvoiceParser;
use App\Domain\Sri\Data\SriImportResult;
use App\Domain\Sri\Exceptions\SriXmlException;

/**
 * Importa un XML autorizado subido manualmente por un usuario.
 *
 * Es la contingencia operativa de US-14: no crea `SriSyncRun` ni encola un
 * job, solo interpreta el documento y lo persiste con `source=manual_upload`.
 * La acción recibe el XML ya leído para no acoplar el dominio a HTTP.
 */
final readonly class ImportManualSriInvoice
{
    private const MAX_BYTES = 2_097_152;

    public function __construct(
        private SriInvoiceParser $parser,
        private PersistSriInvoice $persist,
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

        return $this->persist->handle(
            $company,
            $data,
            $xml,
            null,
            Invoice::SOURCE_MANUAL_UPLOAD,
        );
    }
}
