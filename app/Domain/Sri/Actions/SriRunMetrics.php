<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Sri\Enums\SriSyncRunFileStatus;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Sri\Models\SriSyncRunFile;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Calcula y registra las métricas de un run al cerrarse.
 *
 * El log es la fuente de las métricas operativas: una sola línea estructurada
 * por run con duración, volumen y resultado. Los conteos de comprobantes se
 * leen de `sri_sync_run_files`, no de memoria, para que un cierre parcial o
 * tardío refleje lo que realmente quedó persistido.
 *
 * Nunca debe romper el cierre del run: si el cálculo falla, se reporta y el
 * run sigue su curso.
 */
final readonly class SriRunMetrics
{
    /**
     * @return array{
     *     run_id: int,
     *     tenant_id: int,
     *     company_id: int,
     *     status: string,
     *     error_code: string|null,
     *     duration_ms: int,
     *     totals: array{found: int, imported: int, updated: int, skipped: int, failed: int, from_s3: int, from_sri: int},
     *     documents: array{total: int, processed: int, missing: int, invalid: int, from_s3: int, from_sri: int}
     * }
     */
    public function handle(SriSyncRun $run): array
    {
        $rows = SriSyncRunFile::query()
            ->withoutGlobalScopes()
            ->where('run_id', $run->id)
            ->where('s3_key', 'like', '%-xml/%')
            ->get(['status', 'source']);

        $totals = [
            'found' => (int) ($run->totals['found'] ?? 0),
            'imported' => (int) ($run->totals['imported'] ?? 0),
            'updated' => (int) ($run->totals['updated'] ?? 0),
            'skipped' => (int) ($run->totals['skipped'] ?? 0),
            'failed' => (int) ($run->totals['failed'] ?? 0),
            'from_s3' => (int) ($run->totals['from_s3'] ?? 0),
            'from_sri' => (int) ($run->totals['from_sri'] ?? 0),
        ];

        return [
            'run_id' => $run->id,
            'tenant_id' => $run->tenant_id,
            'company_id' => $run->company_id,
            'status' => $run->status->value,
            'error_code' => $run->error_code,
            'duration_ms' => $this->durationMs($run),
            'totals' => $totals,
            'documents' => [
                'total' => $rows->count(),
                'processed' => $rows->where('status', SriSyncRunFileStatus::Processed)->count(),
                'missing' => $rows->where('status', SriSyncRunFileStatus::Missing)->count(),
                'invalid' => $rows->where('status', SriSyncRunFileStatus::Invalid)->count(),
                'from_s3' => $rows->where('source', 's3')->count(),
                'from_sri' => $rows->where('source', 'sri')->count(),
            ],
        ];
    }

    /**
     * Emite la línea de log con el nivel adecuado al resultado del run.
     *
     * Un run `failed` se registra como error para que el proveedor de logs
     * pueda alertar; `partial` como warning y el resto como información.
     */
    public function record(SriSyncRun $run): void
    {
        try {
            $metrics = $this->handle($run);

            match ($run->status) {
                SriSyncRunStatus::Failed => Log::error('sri.run.failed', $metrics),
                SriSyncRunStatus::Partial => Log::warning('sri.run.partial', $metrics),
                default => Log::info('sri.run.finished', $metrics),
            };
        } catch (Throwable $exception) {
            // La observabilidad no puede impedir que el run se cierre.
            report($exception);
        }
    }

    private function durationMs(SriSyncRun $run): int
    {
        $start = $run->started_at ?? $run->created_at;
        $end = $run->finished_at ?? now();

        if (! $start instanceof Carbon) {
            return 0;
        }

        return max(0, (int) round($start->diffInMilliseconds($end)));
    }
}
