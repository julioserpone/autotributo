<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Jobs\ProcessSriRunJob;
use App\Domain\Sri\Models\SriSyncRun;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use InvalidArgumentException;

/**
 * Resuelve runs que llevan demasiado tiempo sin avanzar.
 *
 * Un run en `invoking` o `downloading` se intenta recuperar desde S3. Si el
 * robot no publicó nada y ya pasó el plazo del robot, se cierra como `failed`
 * con `error_code=robot_timeout`; si había archivos, la recuperación lo deja
 * listo para importar.
 *
 * Un run en `parsing` se cierra con lo que quedó persistido en
 * `sri_sync_run_files` (posiblemente `failed` con `import_timeout` si no hay
 * nada). Si el bloqueo de importación sigue tomado, el batch está en curso y
 * no se toca.
 *
 * «Sin avanzar» se mide sobre `updated_at`, que se refresca con cada
 * transición y con cada comprobante recuperado desde S3.
 */
final readonly class ResolveStaleSriRun
{
    public function __construct(
        private RecoverSriRunFromS3 $recovery,
        private FinalizeSriRun $finalize,
        private int $staleMinutes = 30,
    ) {
        if ($this->staleMinutes < 1) {
            throw new InvalidArgumentException('El plazo para runs huérfanos debe ser mayor que cero.');
        }
    }

    public function handle(SriSyncRun $run): SriSyncRun
    {
        if (! $this->isStale($run)) {
            return $run;
        }

        if ($run->status === SriSyncRunStatus::Parsing) {
            // Un batch en curso mantiene el bloqueo aunque `updated_at` no se
            // refresque para los XML que vienen del SRI. Se comprueba
            // intentando tomarlo: `Cache::has()` no ve el bloqueo porque la
            // caché multi-tenant prefija las claves normales, no las de lock.
            $lock = Cache::lock(ProcessSriRunJob::lockKeyFor($run->id), 3600);

            if (! $lock->get()) {
                return $run;
            }

            try {
                return $this->finalize->handle(
                    $run,
                    failureCode: 'import_timeout',
                    failureMessage: 'La importación del run no terminó dentro del plazo esperado.',
                );
            } finally {
                $lock->release();
            }
        }

        // `invoking` / `downloading`: el robot pudo terminar sin callback.
        $run = $this->recovery->handle($run);

        if ($run->status === SriSyncRunStatus::Parsing || $run->status->isFinal()) {
            return $run;
        }

        $run->markFailed(
            'robot_timeout',
            'El robot no publicó el paquete dentro del plazo esperado.',
        );

        return $run->refresh();
    }

    public function isStale(SriSyncRun $run): bool
    {
        if (! $run->isActive()) {
            return false;
        }

        $reference = $run->updated_at ?? $run->created_at;

        if (! $reference instanceof Carbon) {
            return false;
        }

        return $reference->copy()->addMinutes($this->staleMinutes)->lessThanOrEqualTo(now());
    }
}
