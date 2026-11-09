<?php

declare(strict_types=1);

namespace App\Http\Resources;

use App\Domain\Sri\Models\SriSyncRun;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Estado de un run de sincronización (F2-09a).
 *
 * `poll_after_ms` solo está presente mientras el run está activo: el cliente
 * lo usa como intervalo de polling y deja de consultar cuando llega `null`.
 *
 * @property-read SriSyncRun $resource
 */
final class SriSyncRunResource extends JsonResource
{
    /** Milisegundos entre consultas mientras el run siga activo. */
    public const POLL_INTERVAL_MS = 5000;

    /**
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        /** @var SriSyncRun $run */
        $run = $this->resource;

        return [
            'id' => $run->public_id,
            'company_id' => $run->relationLoaded('company') ? $run->company?->public_id : null,
            'status' => $run->status->value,
            'is_active' => $run->isActive(),
            'poll_after_ms' => $run->isActive() ? self::POLL_INTERVAL_MS : null,
            'date_from' => $run->date_from?->toDateString(),
            'date_to' => $run->date_to?->toDateString(),
            'periods' => $run->periods,
            'document_types' => $run->document_types,
            'totals' => $run->totals ?? [
                'found' => 0,
                'imported' => 0,
                'skipped' => 0,
                'failed' => 0,
            ],
            'progress' => $this->progress($run),
            'error_code' => $run->error_code,
            'error_message' => $run->error_message,
            'started_at' => $run->started_at?->toIso8601String(),
            'finished_at' => $run->finished_at?->toIso8601String(),
            'created_at' => $run->created_at?->toIso8601String(),
        ];
    }

    /**
     * Porcentaje procesado sobre los documentos encontrados.
     *
     * `null` cuando el robot todavía no reportó cuántos documentos hay: un 0 %
     * ahí sería una mentira para el monitor.
     */
    private function progress(SriSyncRun $run): ?float
    {
        $totals = $run->totals ?? [];
        $found = (int) ($totals['found'] ?? 0);

        if ($found <= 0) {
            return null;
        }

        $processed = (int) ($totals['imported'] ?? 0)
            + (int) ($totals['skipped'] ?? 0)
            + (int) ($totals['failed'] ?? 0);

        return round(min(100, ($processed / $found) * 100), 1);
    }
}
