<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\FiscalDocuments\Models\FiscalDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representación pública de un comprobante.
 *
 * Omite `tenant_id`, `id` interno y la huella completa del XML; expone el
 * origen (`source`) para distinguir la carga manual del robot.
 *
 * @property-read FiscalDocument $resource
 */
final class FiscalDocumentResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $fiscal_document = $this->resource;

        return [
            'id' => $fiscal_document->public_id,
            'document_type' => $fiscal_document->document_type->value,
            'access_key' => $fiscal_document->access_key,
            'authorization_number' => $fiscal_document->authorization_number,
            'number' => $fiscal_document->establishment.'-'.$fiscal_document->emission_point.'-'.$fiscal_document->sequential,
            'issue_date' => $fiscal_document->issue_date?->toDateString(),
            'authorization_date' => $fiscal_document->authorization_date?->toIso8601String(),
            'status' => $fiscal_document->status,
            'environment' => $fiscal_document->environment,
            'direction' => $fiscal_document->direction,
            'issuer_ruc' => $fiscal_document->issuer_ruc,
            'issuer_name' => $fiscal_document->issuer_name,
            'receiver_ruc' => $fiscal_document->receiver_ruc,
            'receiver_name' => $fiscal_document->receiver_name,
            'subtotal_cents' => $fiscal_document->subtotal_cents,
            'discount_cents' => $fiscal_document->discount_cents,
            'tax_cents' => $fiscal_document->tax_cents,
            'ice_cents' => $fiscal_document->ice_cents,
            'irbpnr_cents' => $fiscal_document->irbpnr_cents,
            'tip_cents' => $fiscal_document->tip_cents,
            'total_cents' => $fiscal_document->total_cents,
            'currency' => $fiscal_document->currency,
            'source' => $fiscal_document->source,
            'totals_mismatch' => $fiscal_document->totals_mismatch,
            'items' => $fiscal_document->relationLoaded('items')
                ? FiscalDocumentItemResource::collection($fiscal_document->items)
                : [],
            'created_at' => $fiscal_document->created_at?->toIso8601String(),
            'updated_at' => $fiscal_document->updated_at?->toIso8601String(),
        ];
    }
}
