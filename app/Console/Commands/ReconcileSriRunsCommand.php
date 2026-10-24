<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sri\Actions\ReconcileSriRuns;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reconcilia contra S3 los runs que siguen activos.
 *
 * El callback del robot no existe todavía; este comando es el mecanismo de
 * recuperación y de progreso. Se ejecuta cada 10 minutos desde el scheduler.
 */
final class ReconcileSriRunsCommand extends Command
{
    protected $signature = 'sri:reconcile-runs
                            {--limit=50 : Número máximo de runs a reconciliar}';

    protected $description = 'Reconcilia los runs activos del robot SRI contra su bucket S3';

    public function handle(TenantContext $context, ReconcileSriRuns $reconcile): int
    {
        try {
            if (! $context->isInitialized()) {
                $context->initializeDefault();
            }

            $runs = $reconcile->handle((int) $this->option('limit'));
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No fue posible reconciliar los runs del robot SRI.');

            return self::FAILURE;
        }

        $this->info(sprintf('Runs reconciliados: %d', count($runs)));

        return self::SUCCESS;
    }
}
