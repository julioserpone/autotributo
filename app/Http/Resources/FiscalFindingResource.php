<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Models\FiscalFinding;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Representación pública de un hallazgo fiscal.
 *
 * Expone el `public_id` de la empresa y del comprobante de origen (nunca los
 * identificadores internos) para que la UI pueda filtrar y abrir el detalle sin
 * conocer la numeración de la base de datos.
 *
 * @mixin FiscalFinding
 */
final class FiscalFindingResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $finding = $this->resource;

        $company = $finding->relationLoaded('company') ? $finding->company : null;
        $source = $finding->relationLoaded('sourceDocument') ? $finding->sourceDocument : null;

        return [
            'id' => $finding->id,
            'code' => $finding->code->value,
            'severity' => $finding->severity->value,
            'status' => $finding->status->value,
            'title' => $finding->title,
            'description' => $finding->description,
            'company' => $company instanceof Company
                ? [
                    'id' => $company->public_id,
                    'ruc' => $company->ruc,
                    'legal_name' => $company->legal_name,
                ]
                : null,
            'source_type' => $finding->source_type,
            'source' => $source instanceof FiscalDocument
                ? [
                    'type' => 'fiscal_document',
                    'id' => $source->public_id,
                    'document_type' => $source->document_type->value,
                    'issue_date' => $source->issue_date?->toDateString(),
                    'issuer_name' => $source->issuer_name,
                    'issuer_ruc' => $source->issuer_ruc,
                    'total_cents' => $source->total_cents,
                ]
                : null,
            'metadata' => $finding->metadata,
            'note' => is_array($finding->metadata) ? ($finding->metadata['note'] ?? null) : null,
            'resolved_by' => $finding->relationLoaded('resolver') && $finding->resolver !== null
                ? ['id' => $finding->resolver->id, 'name' => $finding->resolver->name]
                : null,
            'resolved_at' => $finding->resolved_at?->toIso8601String(),
            'created_at' => $finding->created_at?->toIso8601String(),
            'updated_at' => $finding->updated_at?->toIso8601String(),
        ];
    }
}
