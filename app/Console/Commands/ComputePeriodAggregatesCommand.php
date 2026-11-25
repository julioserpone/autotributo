<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Actions\ComputePeriodAggregates;
use App\Domain\Fiscal\Models\FiscalPeriod;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;
use Throwable;

/**
 * Recalcula los agregados materializados de un período fiscal.
 *
 * Uso: `php artisan fiscal:compute-aggregates {company?} {--company=} {--period=YYYY-MM}`
 *
 * F5-07d: devuelve código distinto de cero si algún período falla, para que el
 * scheduler y el operador detecten el fallo sin revisar logs. El estado de cada
 * período queda además en `fiscal_periods.aggregation_*`.
 */
final class ComputePeriodAggregatesCommand extends Command
{
    protected $signature = 'fiscal:compute-aggregates
        {company? : public_id de la empresa}
        {--company= : public_id de la empresa (alternativa al argumento)}
        {--period= : Período YYYY-MM (por defecto, todos los meses con comprobantes)}';

    protected $description = 'Recalcula los agregados materializados por período fiscal';

    public function handle(ComputePeriodAggregates $compute, TenantContext $context): int
    {
        if (! $context->isInitialized()) {
            $context->initializeDefault();
        }

        $companyPublicId = $this->argument('company') ?? $this->option('company');

        $companies = is_string($companyPublicId) && $companyPublicId !== ''
            ? Company::query()->where('public_id', $companyPublicId)->get()
            : Company::query()->get();

        if ($companies->isEmpty()) {
            $this->warn('No se encontraron empresas.');

            return self::SUCCESS;
        }

        $period = $this->option('period');
        $failures = 0;

        foreach ($companies as $company) {
            if (is_string($period) && $period !== '') {
                if (! $this->recompute($compute, $company, $period)) {
                    $failures++;
                }

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
                $month = sprintf('%04d-%02d', (int) $row->y, (int) $row->m);

                if (! $this->recompute($compute, $company, $month)) {
                    $failures++;
                }
            }
        }

        if ($failures > 0) {
            $this->error(sprintf('%d período(s) no se pudieron agregar.', $failures));

            return self::FAILURE;
        }

        return self::SUCCESS;
    }

    private function recompute(ComputePeriodAggregates $compute, Company $company, string $period): bool
    {
        $parts = explode('-', $period);

        if (count($parts) !== 2) {
            $this->error(sprintf('%s: período inválido «%s». Use YYYY-MM.', $company->legal_name, $period));

            return false;
        }

        [$year, $month] = array_map('intval', $parts);

        try {
            $periodModel = $compute->handle($company, $year, $month);
        } catch (Throwable $exception) {
            $this->error(sprintf(
                '%s: período %04d-%02d falló (%s).',
                $company->legal_name,
                $year,
                $month,
                $exception->getMessage(),
            ));

            return false;
        }

        $this->info(sprintf(
            '%s: período %04d-%02d recalculado (%d agregados, estado %s).',
            $company->legal_name,
            $year,
            $month,
            $periodModel->aggregates()->count(),
            $periodModel->aggregation_status ?? FiscalPeriod::AGGREGATION_PENDING,
        ));

        return true;
    }
}
