<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Actions\ImportSriRun;
use App\Domain\Sri\Actions\RecoverSriRunFromS3;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Console\Command;
use Throwable;

/**
 * Importa de forma explícita un manifiesto manual del robot (origen=manual)
 * para un período. No invoca al robot: consume los archivos que ya están en S3.
 */
final class ImportManualSriRunCommand extends Command
{
    protected $signature = 'sri:import-manual
                            {company : ID o public_id de la empresa}
                            {period : Período AAAA-MM}
                            {--no-import : Solo reconcilia y verifica el período en S3}';

    protected $description = 'Importa un manifiesto manual del robot SRI para un período';

    public function handle(TenantContext $context, RecoverSriRunFromS3 $recovery, ImportSriRun $import): int
    {
        try {
            if (! $context->isInitialized()) {
                $context->initializeDefault();
            }

            $company = $this->resolveCompany();

            if ($company === null) {
                $this->error('La empresa indicada no existe.');

                return self::FAILURE;
            }

            $period = (string) $this->argument('period');

            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $period) !== 1) {
                $this->error('El período debe tener el formato AAAA-MM.');

                return self::FAILURE;
            }

            $from = CarbonImmutable::parse($period.'-01')->startOfMonth();
            $to = $from->endOfMonth();

            $run = SriSyncRun::create([
                'company_id' => $company->id,
                'status' => SriSyncRunStatus::Invoking,
                'date_from' => $from->toDateString(),
                'date_to' => $to->toDateString(),
                'periods' => [$period],
                'document_types' => SriDocumentType::syncableCodes(),
                'idempotency_key' => 'manual-'.bin2hex(random_bytes(8)),
                'totals' => [
                    'found' => 0,
                    'imported' => 0,
                    'skipped' => 0,
                    'failed' => 0,
                ],
            ]);

            $run = $recovery->handle($run, allowManual: true);
            $this->info(sprintf('Run %s: %s', $run->public_id, $run->status->value));

            if ($this->option('no-import')) {
                return self::SUCCESS;
            }

            if ($run->status !== SriSyncRunStatus::Parsing) {
                $this->warn('El período del run todavía no está completo en S3; reintente cuando el robot termine.');

                return self::FAILURE;
            }

            $result = $import->handle($run);
        } catch (Throwable $exception) {
            report($exception);
            $this->error('No fue posible importar el manifiesto manual del robot SRI.');

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

    private function resolveCompany(): ?Company
    {
        $value = (string) $this->argument('company');

        if (ctype_digit($value)) {
            return Company::query()->withoutGlobalScopes()->find((int) $value);
        }

        return Company::query()->withoutGlobalScopes()->where('public_id', $value)->first();
    }
};