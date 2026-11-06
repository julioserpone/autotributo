<?php

declare(strict_types=1);

namespace App\Domain\Sri\Observers;

use App\Domain\Sri\Actions\SriRunMetrics;
use App\Domain\Sri\Models\SriSyncRun;

/**
 * Registra las métricas de un run cuando pasa a un estado final.
 *
 * Se engancha al evento `updated` en lugar de a cada acción para cubrir todos
 * los caminos de cierre: importación, callback del robot, cancelación, timeout
 * y recuperación desde S3. `wasChanged('status')` evita duplicar la línea
 * cuando el run se vuelve a guardar ya cerrado.
 */
final readonly class SriSyncRunObserver
{
    public function __construct(private SriRunMetrics $metrics) {}

    public function updated(SriSyncRun $run): void
    {
        if (! $run->wasChanged('status') || ! $run->status->isFinal()) {
            return;
        }

        $this->metrics->record($run);
    }
}
