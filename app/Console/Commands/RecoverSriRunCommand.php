<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Sri\Actions\ImportSriRun;
use App\Domain\Sri\Actions\RecoverSriRunFromS3;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Recupera un run desde S3 cuando el callback del robot no llegó.
 *
 * Sirve para el caso en que la URL del callback estaba caída al terminar el
 * robot: no hay evento que mueva el run, pero el robot sí dejó el lote en su
 * bucket. El comando verifica el período (manifiesto o lote de claves),
 * reconcilia los archivos, interpreta el TXT e importa los comprobantes.
 *
 * Es idempotente: repetirlo no duplica comprobantes ni archivos.
 */
final class RecoverSriRunCommand extends Command
{
    protected $signature = 'sri:recover-run
                            {run : ID o public_id del run}
                            {--no-import : Solo reconcilia y verifica el período en S3}';

    protected $description = 'Recupera desde S3 un run del robot SRI cuyo callback no llegó';

    public function handle(TenantContext $context, RecoverSriRunFromS3 $recovery, ImportSriRun $import): int
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

            /*if ($run->status->isFinal()) {
                $this->warn("El run ya terminó en {$run->status->value}.");

                return self::FAILURE;
            }*/

            $run = $recovery->handle($run);
            $this->info(sprintf('Run %s: %s', $run->public_id, $run->status->value));

            if ($this->option('no-import')) {
                return self::SUCCESS;
            }

            if ($run->status === SriSyncRunStatus::Completed) {
                $this->line('El robot no publicó comprobantes para el período; el run quedó cerrado sin pendientes.');

                return self::SUCCESS;
            }

            if ($run->status !== SriSyncRunStatus::Parsing) {
                $this->warn('El período del run todavía no está completo en S3; reintente cuando el robot termine.');

                return self::FAILURE;
            }

            $result = $import->handle($run);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No fue posible recuperar el run del robot SRI.');

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
