<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Classification\Actions\ClassifyFiscalDocumentItems;
use App\Domain\Companies\Models\Company;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Sri\Data\SriFiscalDocumentData;
use App\Domain\Sri\Data\SriFiscalDocumentItemData;
use App\Domain\Sri\Data\SriFiscalDocumentTaxData;
use App\Domain\Sri\Data\SriImportResult;
use App\Domain\Sri\Data\SriRetentionData;
use App\Domain\Sri\Enums\SriImportOutcome;
use App\Domain\Sri\Models\SriSyncRun;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Persiste un comprobante de forma idempotente.
 *
 * La identidad es `(tenant_id, company_id, access_key)`. El resultado depende
 * de la huella del XML:
 *
 * | Situación | Resultado |
 * |---|---|
 * | No existe | `created`, con todos los hijos |
 * | Existe y el `xml_sha256` coincide | `skipped`, no se escribe nada |
 * | Existe y el `xml_sha256` difiere | `updated`, los hijos se reemplazan |
 *
 * `first_seen_at` conserva el primer run que vio el comprobante;
 * `sync_run_id` y `updated_or_created_at` apuntan al último que lo tocó.
 * Cada comprobante se escribe en su propia transacción: un XML con hijos
 * inválidos no deja el comprobante a medias.
 */
final readonly class PersistSriFiscalDocument
{
    public function __construct(private ClassifyFiscalDocumentItems $classifyItems) {}

    public function handle(
        Company $company,
        SriFiscalDocumentData $data,
        string $xml,
        ?SriSyncRun $run = null,
        string $source = FiscalDocument::SOURCE_SRI_ROBOT,
        bool $force = false,
    ): SriImportResult {
        $checksum = hash('sha256', $xml);
        $now = now();

        $result = DB::transaction(function () use ($company, $data, $xml, $checksum, $run, $source, $now, $force): SriImportResult {
            $fiscal_document = FiscalDocument::query()
                ->where('company_id', $company->id)
                ->where('access_key', $data->accessKey)
                ->first();

            if ($fiscal_document !== null && $fiscal_document->xml_sha256 === $checksum && ! ($force || ($run instanceof SriSyncRun && $run->force))) {
                return new SriImportResult($fiscal_document, SriImportOutcome::Skipped);
            }

            $isNew = $fiscal_document === null;
            $fiscal_document ??= new FiscalDocument;

            $fiscal_document->fill([
                'company_id' => $company->id,
                'document_type' => $data->documentType,
                'access_key' => $data->accessKey,
                'authorization_number' => $data->authorizationNumber === '' ? null : $data->authorizationNumber,
                'establishment' => $data->establishment,
                'emission_point' => $data->emissionPoint,
                'sequential' => $data->sequential,
                'issue_date' => $data->issueDate,
                'authorization_date' => $data->authorizationDate,
                'status' => FiscalDocument::STATUS_AUTHORIZED,
                'environment' => $data->environment,
                'direction' => $data->issuerRuc === $company->ruc
                    ? FiscalDocument::DIRECTION_ISSUED
                    : FiscalDocument::DIRECTION_RECEIVED,
                'issuer_ruc' => $data->issuerRuc,
                'issuer_name' => $data->issuerName,
                'receiver_ruc' => $data->receiverRuc === '' ? null : $data->receiverRuc,
                'receiver_name' => $data->receiverName === '' ? null : $data->receiverName,
                'subtotal_cents' => $data->subtotalCents,
                'discount_cents' => $data->discountCents,
                'tax_cents' => $data->taxCents,
                'ice_cents' => $data->iceCents,
                'irbpnr_cents' => $data->irbpnrCents,
                'tip_cents' => $data->tipCents,
                'total_cents' => $data->totalCents,
                'currency' => $data->currency,
                'xml_path' => $this->xmlPath($company, $data),
                'xml_sha256' => $checksum,
                'xml_size' => strlen($xml),
                'raw' => null,
                'source' => $source,
                'totals_mismatch' => $data->totalsMismatch(),
                'first_seen_at' => $isNew ? $now : $fiscal_document->first_seen_at,
                'updated_or_created_at' => $now,
                'sync_run_id' => $run?->id,
            ]);

            $fiscal_document->save();

            if (! $isNew) {
                $fiscal_document->items()->delete();
                $fiscal_document->taxes()->delete();
                $fiscal_document->retentions()->delete();
            }

            $this->storeChildren($fiscal_document, $data);

            return new SriImportResult(
                $fiscal_document,
                $isNew ? SriImportOutcome::Created : SriImportOutcome::Updated,
            );
        });

        // La clasificación es un valor añadido: si falla, el comprobante ya
        // quedó persistido y el ítem sigue `pending` para revisión manual.
        if ($result->outcome !== SriImportOutcome::Skipped) {
            try {
                $this->classifyItems->handle($result->fiscal_document);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $result;
    }

    /**
     * Clave S3 lógica del XML: `{ambiente}/{ruc}/{periodo}/{clave}.xml`.
     *
     * F2-08 aún no escribe el XML en el bucket; se guarda la ruta prevista para
     * que el objeto y la fila no se desincronicen cuando se implemente.
     */
    private function xmlPath(Company $company, SriFiscalDocumentData $data): string
    {
        return sprintf(
            '%s/%s/%s/%s.xml',
            $data->environment,
            $company->ruc,
            substr($data->issueDate, 0, 7),
            $data->accessKey,
        );
    }

    private function storeChildren(FiscalDocument $fiscal_document, SriFiscalDocumentData $data): void
    {
        foreach ($data->items as $item) {
            $fiscal_document->items()->create($this->itemAttributes($fiscal_document, $item));
        }

        foreach ($data->taxes as $tax) {
            $fiscal_document->taxes()->create($this->taxAttributes($fiscal_document, $tax));
        }

        foreach ($data->retentions as $retention) {
            $fiscal_document->retentions()->create($this->retentionAttributes($fiscal_document, $retention));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function itemAttributes(FiscalDocument $fiscal_document, SriFiscalDocumentItemData $item): array
    {
        return [
            'tenant_id' => $fiscal_document->tenant_id,
            'line_number' => $item->lineNumber,
            'main_code' => $item->mainCode,
            'auxiliary_code' => $item->auxiliaryCode,
            'description' => $item->description,
            'normalized_description' => $item->normalizedDescription,
            'quantity' => $item->quantity,
            'unit_price' => $item->unitPrice,
            'discount' => $item->discount,
            'subtotal_cents' => $item->subtotalCents,
            'total_cents' => $item->totalCents,
            'classification_status' => 'pending',
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function taxAttributes(FiscalDocument $fiscal_document, SriFiscalDocumentTaxData $tax): array
    {
        return [
            'tenant_id' => $fiscal_document->tenant_id,
            'tax_code' => $tax->taxCode,
            'sri_percentage_code' => $tax->sriPercentageCode,
            'rate' => $tax->rate,
            'base_cents' => $tax->baseCents,
            'amount_cents' => $tax->amountCents,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function retentionAttributes(FiscalDocument $fiscal_document, SriRetentionData $retention): array
    {
        return [
            'tenant_id' => $fiscal_document->tenant_id,
            'tax_type' => $retention->taxType,
            'retention_code' => $retention->retentionCode,
            'base_cents' => $retention->baseCents,
            'rate' => $retention->rate,
            'amount_cents' => $retention->amountCents,
            'document_number' => $retention->supportDocumentNumber === ''
                ? null
                : $retention->supportDocumentNumber,
        ];
    }
}
