<?php

declare(strict_types=1);

namespace App\Domain\Sri\Jobs;

use App\Domain\Sri\Actions\ReconcileSriRun;
use App\Domain\Sri\Actions\RecoverSriRunFromS3;
use App\Domain\Sri\Actions\ResolveStaleSriRun;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Jobs\Concerns\TenantAware;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * Sondea S3 tras invocar el robot cuando no hay callback, hasta que el run
 * avance a `parsing` o se agote el plazo. Permite finalizar un run sin
 * depender del callback firmado del robot.
 */
final class AwaitSriRobotResultJob implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 31;

    public int $timeout = 60;

    public function __construct(
        public readonly int $runId,
        public int $attemptsRemaining = 30,
    ) {
        $this->queue = (string) config('sri.sync.import_queue', 'sri');
        // El job se re-encola con `release()` hasta agotar `attemptsRemaining`;
        // `tries` debe cubrir todos esos reintentos, si no el worker lo descarta
        // a la primera re-encolación.
        $this->tries = max(1, $attemptsRemaining + 1);
    }

    protected function handleInTenant(TenantContext $context): void
    {
        $run = SriSyncRun::query()->find($this->runId);

        if (! $run instanceof SriSyncRun || ! $run->isActive()) {
            return;
        }

        if (! in_array($run->status, [SriSyncRunStatus::Invoking, SriSyncRunStatus::Downloading], true)) {
            return;
        }

        $reconcile = app(ReconcileSriRun::class);
        $recovery = app(RecoverSriRunFromS3::class);
        $stale = app(ResolveStaleSriRun::class);

        $run = $reconcile->handle($run);
        $run = $recovery->handle($run);

        if ($run->status === SriSyncRunStatus::Parsing) {
            ProcessSriRunJob::dispatch($run->id);

            return;
        }

        if ($run->status === SriSyncRunStatus::Invoking || $run->status === SriSyncRunStatus::Downloading) {
            if ($this->attemptsRemaining <= 1) {
                $stale->handle($run);

                return;
            }

            $this->attemptsRemaining--;

            $delay = (int) config('sri.sync.await_robot_delay_seconds', 10);

            $this->release($delay);
        }
    }
}
