<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Jobs\ProcessSriRunJob;
use App\Domain\Sri\Models\SriSyncRun;
use Illuminate\Support\Facades\Log;
use InvalidArgumentException;
use Throwable;

/**
 * Reconciliación periódica de los runs activos.
 *
 * El scheduler llama a esta acción cada 10 minutos. Cada run se reconcilia de
 * forma independiente; un fallo de un run no impide procesar los siguientes.
 */
final readonly class ReconcileSriRuns
{
    public function __construct(
        private ReconcileSriRun $reconcile,
        private ResolveStaleSriRun $stale,
        private int $orphanAlertThreshold = 3,
    ) {
        if ($this->orphanAlertThreshold < 1) {
            throw new InvalidArgumentException('El umbral de alerta de runs huérfanos debe ser mayor que cero.');
        }
    }

    /**
     * @return list<SriSyncRun>
     */
    public function handle(int $limit = 50): array
    {
        if ($limit < 1) {
            throw new InvalidArgumentException('El límite de reconciliación debe ser mayor que cero.');
        }

        $statuses = array_map(
            static fn (SriSyncRunStatus $status): string => $status->value,
            [SriSyncRunStatus::Invoking, SriSyncRunStatus::Downloading, SriSyncRunStatus::Parsing],
        );

        $runs = SriSyncRun::query()
            ->whereIn('status', $statuses)
            ->orderBy('id')
            ->limit($limit)
            ->get();

        $reconciled = [];
        $orphans = [];

        foreach ($runs as $run) {
            try {
                $reconciledRun = $this->stale->handle($this->reconcile->handle($run));
                $reconciled[] = $reconciledRun;

                if ($reconciledRun->status === SriSyncRunStatus::Parsing) {
                    ProcessSriRunJob::dispatch($reconciledRun->id);
                }

                if (in_array($reconciledRun->error_code, ['robot_timeout', 'import_timeout'], true)) {
                    $orphans[] = $reconciledRun->id;
                }
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        if (count($orphans) > $this->orphanAlertThreshold) {
            Log::warning('varios runs SRI huérfanos en la última reconciliación', [
                'count' => count($orphans),
                'run_ids' => $orphans,
            ]);
        }

        return $reconciled;
    }
}
