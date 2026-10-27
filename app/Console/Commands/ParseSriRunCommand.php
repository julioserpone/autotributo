<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sri\Actions\ParseSriRun;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Interpreta los archivos de un run sin esperar al scheduler.
 *
 * Acepta un run en `parsing` (lo normal tras `sri:reconcile-runs`) o en
 * `downloading` (el callback llegó y los archivos ya están en S3). Repetirlo
 * no duplica nada: los archivos ya procesados no se vuelven a leer y un run
 * final no se mueve.
 */
final class ParseSriRunCommand extends Command
{
    protected $signature = 'sri:parse-run
                            {run : ID o public_id del run}';

    protected $description = 'Interpreta el TXT de claves de un run del robot SRI';

    public function handle(TenantContext $context, ParseSriRun $parse): int
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

            $status = $run->status->value;

            if (! in_array($status, ['parsing', 'downloading'], true)) {
                $this->warn("El run está en {$status}; solo se procesan runs en parsing o downloading.");

                return self::FAILURE;
            }

            $result = $parse->handle($run);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No fue posible interpretar el run del robot SRI.');

            return self::FAILURE;
        }

        $this->info(sprintf('Run %s: %s', $result['run']->public_id, $result['run']->status->value));

        foreach ($result['batches'] as $batch) {
            $this->line(sprintf(
                '  %s %s → %d claves (%d inválidas, %d faltantes, %d no listadas)',
                $batch->period,
                $batch->documentType->value,
                $batch->count(),
                count($batch->invalidAccessKeys),
                count($batch->missingAccessKeys),
                count($batch->unlistedAccessKeys),
            ));
        }

        return $result['run']->status->value === 'failed' ? self::FAILURE : self::SUCCESS;
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
