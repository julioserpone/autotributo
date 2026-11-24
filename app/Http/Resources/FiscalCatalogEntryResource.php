<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Fiscal\Models\FormLine;
use App\Domain\Fiscal\Models\PersonalExpenseCap;
use App\Domain\Fiscal\Models\RetentionRate;
use App\Domain\Fiscal\Models\TaxRate;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Fila de un catálogo fiscal versionado (F3-09).
 *
 * Los cuatro catálogos comparten la misma forma pública: código, etiqueta,
 * vigencia y procedencia. Los campos propios se exponen cuando existen, para
 * que la UI pueda listarlos con una sola tabla.
 *
 * `rate` se devuelve como cadena decimal (no como float) porque es una tarifa
 * publicada, no un monto calculado; `*_cents` van como enteros.
 *
 * @property-read TaxRate|RetentionRate|FormLine|PersonalExpenseCap $resource
 */
final class FiscalCatalogEntryResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $entry = $this->resource;

        $payload = [
            'id' => $entry->getKey(),
            'valid_from' => $this->date('valid_from'),
            'valid_to' => $this->date('valid_to'),
            'is_current' => $this->isCurrent($entry),
            'source' => $entry->getAttribute('source'),
            'published_at' => $this->date('published_at'),
        ];

        return [...$this->ownFields($entry), ...$payload];
    }

    /**
     * @return array<string, mixed>
     */
    private function ownFields(TaxRate|RetentionRate|FormLine|PersonalExpenseCap $entry): array
    {
        return match (true) {
            $entry instanceof TaxRate => [
                'code' => $entry->code,
                'sri_percentage_code' => $entry->sri_percentage_code,
                'rate' => $this->rate($entry),
                'label' => $entry->label,
                'applies_to' => $entry->applies_to,
                'legal_basis' => $entry->legal_basis,
            ],
            $entry instanceof RetentionRate => [
                'tax_type' => $entry->tax_type->value,
                'code' => $entry->code,
                'rate' => $this->rate($entry),
                'label' => $entry->label,
                'applies_to' => $entry->applies_to,
                'legal_basis' => $entry->legal_basis,
            ],
            $entry instanceof FormLine => [
                'form_code' => $entry->form_code,
                'code' => $entry->line_code,
                'section' => $entry->section,
                'label' => $entry->label,
                'data_type' => $entry->data_type->value,
                'is_required' => (bool) $entry->is_required,
                'display_order' => (int) $entry->display_order,
            ],
            default => [
                'fiscal_year' => (int) $entry->fiscal_year,
                'code' => $entry->category_code,
                'dependents' => (int) $entry->dependents,
                'cap_cents' => (int) $entry->cap_cents,
                'baskets' => $entry->baskets,
                'basic_food_basket_cents' => (int) $entry->basic_food_basket_cents,
            ],
        };
    }

    private function rate(TaxRate|RetentionRate $entry): string
    {
        $value = (float) $entry->rate;

        $formatted = rtrim(rtrim(number_format($value, 6, '.', ''), '0'), '.');

        return $formatted === '' ? '0' : $formatted;
    }

    private function date(string $attribute): ?string
    {
        $value = $this->resource->getAttribute($attribute);

        return $value === null ? null : (string) $value;
    }

    private function isCurrent(TaxRate|RetentionRate|FormLine|PersonalExpenseCap $entry): bool
    {
        return $entry->isValidOn(now());
    }
}
