<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Jobs;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Actions\ComputePeriodAggregates;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Jobs\Concerns\TenantAware;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

/**
 * Recalcula los agregados de los meses cubiertos por un run del SRI (F5-07d).
 *
 * Vive fuera de `FinalizeSriRun` para que un fallo de agregación no dependa
 * del `try/catch` del cierre del run: el job registra el motivo en
 * `fiscal_periods.aggregation_*` y conserva el run como estaba. Corre en su
 * propia cola de trabajo para no competir con la importación.
 */
final class RecomputeSriRunAggregatesJob implements ShouldQueue
{
    use Queueable, TenantAware;

    public int $tries = 3;

    public int $timeout = 120;

    public function __construct(public readonly int $runId) {}

    public static function forRun(SriSyncRun $run): self
    {
        return new self($run->id);
    }

    protected function handleInTenant(TenantContext $context): void
    {
        $run = SriSyncRun::query()->find($this->runId);

        if (! $run instanceof SriSyncRun) {
            Log::warning('recálculo de agregados sin run en el tenant activo', [
                'run_id' => $this->runId,
                'tenant_id' => $context->id(),
            ]);

            return;
        }

        $company = Company::query()->find($run->company_id);

        if (! $company instanceof Company) {
            return;
        }

        $compute = app(ComputePeriodAggregates::class);

        $cursor = $run->date_from->copy()->startOfMonth();
        $end = $run->date_to->copy()->endOfMonth();

        while ($cursor->lessThanOrEqualTo($end)) {
            // Let queue retries handle failures; `attempt()` would swallow
            // the exception and mark this job as successful.
            $compute->handle($company, $cursor->year, $cursor->month);

            $cursor->addMonthNoOverflow();
        }
    }
}
