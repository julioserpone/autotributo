<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sri\Actions\ImportSriRun;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Importa los comprobantes de un run sin esperar al scheduler.
 *
 * Acepta un run en `parsing` (lo normal tras `sri:parse-run`) o en
 * `downloading` (el callback llegó y los archivos ya están en S3). Repetirlo
 * no duplica comprobantes: el mismo XML se reporta como `skipped`.
 */
final class ImportSriRunCommand extends Command
{
    protected $signature = 'sri:import-run
                            {run : ID o public_id del run}';

    protected $description = 'Consulta, interpreta y persiste los comprobantes de un run del robot SRI';

    public function handle(TenantContext $context, ImportSriRun $import): int
    {
        try {
            if (! $context->isInitialized()) {
                $context->initializeDefault();
            }

            $run = SriSyncRun::query()->withoutGlobalScopes()->find($this->resolveRunId());

            if ($run === null) {
                $this->error('El run indicado no existe.');

                return self::FAILURE;
            }

            if (! in_array($run->status->value, ['parsing', 'downloading'], true)) {
                $this->warn("El run está en {$run->status->value}; solo se importan runs en parsing o downloading.");

                return self::FAILURE;
            }

            $result = $import->handle($run);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No fue posible importar el run del robot SRI.');

            return self::FAILURE;
        }

        $report = $result['report'];

        $this->info(sprintf('Run %s: %s', $result['run']->public_id, $report->status->value));
        $this->line(sprintf(
            '  encontrados=%d importados=%d actualizados=%d omitidos=%d fallidos=%d',
            $report->found,
            $report->imported,
            $report->updated,
            $report->skipped,
            $report->failed,
        ));

        return $report->status->value === 'failed' ? self::FAILURE : self::SUCCESS;
    }

    private function resolveRunId(): int
    {
        $run = (string) $this->argument('run');

        if (ctype_digit($run)) {
            return (int) $run;
        }

        return (int) SriSyncRun::query()->withoutGlobalScopes()->where('public_id', $run)->value('id');
    }
}
