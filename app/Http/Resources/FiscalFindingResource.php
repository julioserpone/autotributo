<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Fiscal\Models\FiscalFinding;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
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

        return [
            'id' => $finding->id,
            'code' => $finding->code->value,
            'severity' => $finding->severity->value,
            'status' => $finding->status->value,
            'title' => $finding->title,
            'description' => $finding->description,
            'company_id' => $finding->company_id,
            'source_type' => $finding->source_type,
            'source_id' => $finding->source_id,
            'metadata' => $finding->metadata,
            'resolved_by' => $finding->resolver !== null
                ? ['id' => $finding->resolver->id, 'name' => $finding->resolver->name]
                : null,
            'resolved_at' => $finding->resolved_at?->toIso8601String(),
            'created_at' => $finding->created_at?->toIso8601String(),
            'updated_at' => $finding->updated_at?->toIso8601String(),
        ];
    }
}
