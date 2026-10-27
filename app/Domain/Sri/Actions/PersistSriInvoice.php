<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Companies\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Sri\Data\SriImportResult;
use App\Domain\Sri\Data\SriInvoiceData;
use App\Domain\Sri\Data\SriInvoiceItemData;
use App\Domain\Sri\Data\SriInvoiceTaxData;
use App\Domain\Sri\Data\SriRetentionData;
use App\Domain\Sri\Enums\SriImportOutcome;
use App\Domain\Sri\Models\SriSyncRun;
use Illuminate\Support\Facades\DB;

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
final readonly class PersistSriInvoice
{
    public function handle(
        Company $company,
        SriInvoiceData $data,
        string $xml,
        ?SriSyncRun $run = null,
        string $source = Invoice::SOURCE_SRI_ROBOT,
    ): SriImportResult {
        $checksum = hash('sha256', $xml);
        $now = now();

        return DB::transaction(function () use ($company, $data, $xml, $checksum, $run, $source, $now): SriImportResult {
            $invoice = Invoice::query()
                ->where('company_id', $company->id)
                ->where('access_key', $data->accessKey)
                ->first();

            if ($invoice !== null && $invoice->xml_sha256 === $checksum) {
                return new SriImportResult($invoice, SriImportOutcome::Skipped);
            }

            $isNew = $invoice === null;
            $invoice ??= new Invoice;

            $invoice->fill([
                'company_id' => $company->id,
                'document_type' => $data->documentType,
                'access_key' => $data->accessKey,
                'authorization_number' => $data->authorizationNumber === '' ? null : $data->authorizationNumber,
                'establishment' => $data->establishment,
                'emission_point' => $data->emissionPoint,
                'sequential' => $data->sequential,
                'issue_date' => $data->issueDate,
                'authorization_date' => $data->authorizationDate,
                'status' => Invoice::STATUS_AUTHORIZED,
                'environment' => $data->environment,
                'direction' => Invoice::DIRECTION_RECEIVED,
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
                'first_seen_at' => $isNew ? $now : $invoice->first_seen_at,
                'updated_or_created_at' => $now,
                'sync_run_id' => $run?->id,
            ]);

            $invoice->save();

            if (! $isNew) {
                $invoice->items()->delete();
                $invoice->taxes()->delete();
                $invoice->retentions()->delete();
            }

            $this->storeChildren($invoice, $data);

            return new SriImportResult(
                $invoice,
                $isNew ? SriImportOutcome::Created : SriImportOutcome::Updated,
            );
        });
    }

    /**
     * Clave S3 lógica del XML: `{ambiente}/{ruc}/{periodo}/{clave}.xml`.
     *
     * F2-08 aún no escribe el XML en el bucket; se guarda la ruta prevista para
     * que el objeto y la fila no se desincronicen cuando se implemente.
     */
    private function xmlPath(Company $company, SriInvoiceData $data): string
    {
        return sprintf(
            '%s/%s/%s/%s.xml',
            $data->environment,
            $company->ruc,
            substr($data->issueDate, 0, 7),
            $data->accessKey,
        );
    }

    private function storeChildren(Invoice $invoice, SriInvoiceData $data): void
    {
        foreach ($data->items as $item) {
            $invoice->items()->create($this->itemAttributes($invoice, $item));
        }

        foreach ($data->taxes as $tax) {
            $invoice->taxes()->create($this->taxAttributes($invoice, $tax));
        }

        foreach ($data->retentions as $retention) {
            $invoice->retentions()->create($this->retentionAttributes($invoice, $retention));
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function itemAttributes(Invoice $invoice, SriInvoiceItemData $item): array
    {
        return [
            'tenant_id' => $invoice->tenant_id,
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
    private function taxAttributes(Invoice $invoice, SriInvoiceTaxData $tax): array
    {
        return [
            'tenant_id' => $invoice->tenant_id,
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
    private function retentionAttributes(Invoice $invoice, SriRetentionData $retention): array
    {
        return [
            'tenant_id' => $invoice->tenant_id,
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
