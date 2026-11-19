<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Classification\Models\Classification;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\FiscalDocuments\Models\FiscalDocumentItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Clasificación expuesta en la cola de revisión (F3-05).
 *
 * Incluye lo mínimo para decidir sin abrir el comprobante: descripción del
 * ítem, importe, emisor y la confianza del origen. `method` explica de dónde
 * salió la sugerencia (regla, caché o IA).
 *
 * @property-read Classification $resource
 */
final class ClassificationResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $classification = $this->resource;

        $item = $classification->relationLoaded('fiscal_documentItem') && $classification->fiscal_documentItem instanceof FiscalDocumentItem
            ? $classification->fiscal_documentItem
            : null;

        $fiscal_document = $item instanceof FiscalDocumentItem
            && $item->relationLoaded('fiscal_document')
            && $item->fiscal_document instanceof FiscalDocument
            ? $item->fiscal_document
            : null;

        return [
            'id' => $classification->id,
            'status' => $classification->status->value,
            'method' => $classification->method->value,
            'confidence' => $classification->confidence,
            'prompt_version' => $classification->prompt_version,
            'expense_category' => $classification->relationLoaded('expenseCategory') && $classification->expenseCategory !== null
                ? new ExpenseCategoryResource($classification->expenseCategory)
                : null,
            'item' => $item instanceof FiscalDocumentItem ? [
                'id' => $item->id,
                'line_number' => $item->line_number,
                'description' => $item->description,
                'total_cents' => $item->total_cents,
            ] : null,
            'fiscal_document' => $fiscal_document instanceof FiscalDocument ? [
                'id' => $fiscal_document->public_id,
                'document_type' => $fiscal_document->document_type->value,
                'issue_date' => $fiscal_document->issue_date?->toDateString(),
                'issuer_name' => $fiscal_document->issuer_name,
                'issuer_ruc' => $fiscal_document->issuer_ruc,
                'total_cents' => $fiscal_document->total_cents,
            ] : null,
            'reviewed_by' => $classification->relationLoaded('reviewer') && $classification->reviewer !== null
                ? [
                    'id' => $classification->reviewer->id,
                    'name' => $classification->reviewer->name,
                ]
                : null,
            'reviewed_at' => $classification->reviewed_at?->toIso8601String(),
            'created_at' => $classification->created_at?->toIso8601String(),
            'updated_at' => $classification->updated_at?->toIso8601String(),
        ];
    }
}
