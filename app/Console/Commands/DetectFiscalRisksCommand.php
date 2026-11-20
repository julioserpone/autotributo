<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Actions\DetectFiscalRisks;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Console\Command;

/**
 * Detecta riesgos fiscales para una empresa (o todas las del tenant activo).
 *
 * Uso: `php artisan fiscal:detect-risks {company?} {--period=}`
 */
final class DetectFiscalRisksCommand extends Command
{
    protected $signature = 'fiscal:detect-risks {company? : public_id de la empresa} {--period= : Período YYYY-MM (reservado)}';

    protected $description = 'Detecta riesgos fiscales sobre los comprobantes de una empresa';

    public function handle(DetectFiscalRisks $detect, TenantContext $context): int
    {
        if (! $context->isInitialized()) {
            $context->initializeDefault();
        }

        $companyPublicId = $this->argument('company');

        $companies = $companyPublicId !== null
            ? Company::query()->where('public_id', $companyPublicId)->get()
            : Company::query()->get();

        if ($companies->isEmpty()) {
            $this->warn('No se encontraron empresas para analizar.');

            return self::SUCCESS;
        }

        foreach ($companies as $company) {
            $result = $detect->handle($company);

            $this->info(sprintf(
                '%s: %d creados, %d actualizados, %d resueltos (%d hallazgos).',
                $company->legal_name,
                $result->created,
                $result->updated,
                $result->resolved,
                $result->total(),
            ));
        }

        return self::SUCCESS;
    }
}
