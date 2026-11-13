<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Invoicing\Models\Invoice;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representación pública de un comprobante.
 *
 * Omite `tenant_id`, `id` interno y la huella completa del XML; expone el
 * origen (`source`) para distinguir la carga manual del robot.
 *
 * @property-read Invoice $resource
 */
final class InvoiceResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $invoice = $this->resource;

        return [
            'id' => $invoice->public_id,
            'document_type' => $invoice->document_type->value,
            'access_key' => $invoice->access_key,
            'authorization_number' => $invoice->authorization_number,
            'issue_date' => $invoice->issue_date?->toDateString(),
            'authorization_date' => $invoice->authorization_date?->toIso8601String(),
            'status' => $invoice->status,
            'environment' => $invoice->environment,
            'direction' => $invoice->direction,
            'issuer_ruc' => $invoice->issuer_ruc,
            'issuer_name' => $invoice->issuer_name,
            'receiver_ruc' => $invoice->receiver_ruc,
            'receiver_name' => $invoice->receiver_name,
            'subtotal_cents' => $invoice->subtotal_cents,
            'discount_cents' => $invoice->discount_cents,
            'tax_cents' => $invoice->tax_cents,
            'ice_cents' => $invoice->ice_cents,
            'irbpnr_cents' => $invoice->irbpnr_cents,
            'tip_cents' => $invoice->tip_cents,
            'total_cents' => $invoice->total_cents,
            'currency' => $invoice->currency,
            'source' => $invoice->source,
            'totals_mismatch' => $invoice->totals_mismatch,
            'created_at' => $invoice->created_at?->toIso8601String(),
            'updated_at' => $invoice->updated_at?->toIso8601String(),
        ];
    }
}
