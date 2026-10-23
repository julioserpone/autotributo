<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
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
    public function __construct(private ReconcileSriRun $reconcile) {}

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

        foreach ($runs as $run) {
            try {
                $reconciled[] = $this->reconcile->handle($run);
            } catch (Throwable $exception) {
                report($exception);
            }
        }

        return $reconciled;
    }
}
