<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Classification\Models\Classification;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
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

        $item = $classification->relationLoaded('invoiceItem') && $classification->invoiceItem instanceof InvoiceItem
            ? $classification->invoiceItem
            : null;

        $invoice = $item instanceof InvoiceItem
            && $item->relationLoaded('invoice')
            && $item->invoice instanceof Invoice
            ? $item->invoice
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
            'item' => $item instanceof InvoiceItem ? [
                'id' => $item->id,
                'line_number' => $item->line_number,
                'description' => $item->description,
                'total_cents' => $item->total_cents,
            ] : null,
            'invoice' => $invoice instanceof Invoice ? [
                'id' => $invoice->public_id,
                'document_type' => $invoice->document_type->value,
                'issue_date' => $invoice->issue_date?->toDateString(),
                'issuer_name' => $invoice->issuer_name,
                'issuer_ruc' => $invoice->issuer_ruc,
                'total_cents' => $invoice->total_cents,
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
