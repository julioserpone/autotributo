<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Audit\Models\AuditLog;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Entrada del historial de auditoría.
 *
 * `auditable_type` se expone como nombre corto (`company`, `sri_credential`)
 * para que el frontend no dependa del FQCN de PHP.
 *
 * @property-read AuditLog $resource
 */
final class AuditLogResource extends JsonResource
{
    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $log = $this->resource;

        return [
            'id' => $log->id,
            'event' => $log->event,
            'auditable_type' => class_basename($log->auditable_type),
            'auditable_id' => $log->auditable_id,
            'user_id' => $log->user_id,
            'old_values' => $log->old_values,
            'new_values' => $log->new_values,
            'ip_address' => $log->ip_address,
            'created_at' => $log->created_at?->toIso8601String(),
        ];
    }
}
