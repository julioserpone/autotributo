<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Actions\ComputePeriodAggregates;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Recalcula los agregados materializados de un período fiscal.
 *
 * Uso: `php artisan fiscal:compute-aggregates {company?} {--period=YYYY-MM}`
 */
final class ComputePeriodAggregatesCommand extends Command
{
    protected $signature = 'fiscal:compute-aggregates {company? : public_id de la empresa} {--period= : Período YYYY-MM (por defecto, todos los meses con comprobantes)}';

    protected $description = 'Recalcula los agregados materializados por período fiscal';

    public function handle(ComputePeriodAggregates $compute, TenantContext $context): int
    {
        if (! $context->isInitialized()) {
            $context->initializeDefault();
        }

        $companyPublicId = $this->argument('company');

        $companies = $companyPublicId !== null
            ? Company::query()->where('public_id', $companyPublicId)->get()
            : Company::query()->get();

        if ($companies->isEmpty()) {
            $this->warn('No se encontraron empresas.');

            return self::SUCCESS;
        }

        $period = $this->option('period');

        foreach ($companies as $company) {
            if ($period !== null && $period !== '') {
                [$year, $month] = array_map('intval', explode('-', $period));

                $periodModel = $compute->handle($company, $year, $month);

                $this->info(sprintf(
                    '%s: período %04d-%02d recalculado (%d agregados).',
                    $company->legal_name,
                    $year,
                    $month,
                    $periodModel->aggregates()->count(),
                ));

                continue;
            }

            // Sin período: recalcula todos los meses que tienen comprobantes.
            $months = FiscalDocument::query()
                ->where('company_id', $company->id)
                ->selectRaw('EXTRACT(YEAR FROM issue_date)::int AS y, EXTRACT(MONTH FROM issue_date)::int AS m')
                ->distinct()
                ->get();

            if ($months->isEmpty()) {
                $this->info(sprintf('%s: sin comprobantes.', $company->legal_name));

                continue;
            }

            foreach ($months as $row) {
                /** @var object{y: int|string, m: int|string} $row */
                $year = (int) $row->y;
                $month = (int) $row->m;

                $periodModel = $compute->handle($company, $year, $month);

                $this->info(sprintf(
                    '%s: período %04d-%02d recalculado (%d agregados).',
                    $company->legal_name,
                    $year,
                    $month,
                    $periodModel->aggregates()->count(),
                ));
            }
        }

        return self::SUCCESS;
    }
}
