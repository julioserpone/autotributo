<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\FiscalDocuments\Models\FiscalDocumentItem;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Línea de detalle de un comprobante con su clasificación (F3-05).
 *
 * La bandeja necesita ver por qué un ítem quedó en revisión, así que además del
 * estado se expone la categoría asignada, el método, la confianza y el motivo
 * de la sugerencia cuando existe.
 *
 * @property-read FiscalDocumentItem $resource
 */
final class FiscalDocumentItemResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $item = $this->resource;
        $classification = $item->relationLoaded('classification') ? $item->getRelation('classification') : null;

        return [
            'line_number' => $item->line_number,
            'description' => $item->description,
            'main_code' => $item->main_code,
            'auxiliary_code' => $item->auxiliary_code,
            'quantity' => (string) $item->quantity,
            'unit_price' => (string) $item->unit_price,
            'discount' => (string) $item->discount,
            'subtotal_cents' => (int) $item->subtotal_cents,
            'total_cents' => (int) $item->total_cents,
            'classification' => [
                'status' => $item->classification_status,
                'expense_category_id' => $item->expense_category_id === null ? null : (int) $item->expense_category_id,
                'expense_category' => $this->category($item),
                'method' => $classification?->method?->value,
                'confidence' => $classification === null ? null : (float) $classification->confidence,
                'prompt_version' => $classification?->prompt_version,
            ],
        ];
    }

    /**
     * @return array{id: int, code: string, name: string}|null
     */
    private function category(FiscalDocumentItem $item): ?array
    {
        $category = $item->relationLoaded('expenseCategory') ? $item->getRelation('expenseCategory') : null;

        if ($category === null) {
            return null;
        }

        return [
            'id' => (int) $category->getKey(),
            'code' => (string) $category->code,
            'name' => (string) $category->name,
        ];
    }
}
