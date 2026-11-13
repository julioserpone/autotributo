<?php

declare(strict_types=1);

namespace App\Console\Commands;

use App\Domain\Fiscal\Actions\ValidateFiscalCatalogs;
use App\Domain\Fiscal\Models\FormLine;
use App\Domain\Fiscal\Models\PersonalExpenseCap;
use App\Domain\Fiscal\Models\RetentionRate;
use App\Domain\Fiscal\Models\TaxRate;
use Illuminate\Console\Command;

/**
 * Verifica que los catálogos fiscales no tengan vigencias inválidas,
 * solapamientos ni saltos antes de desplegar una reforma.
 */
final class ValidateFiscalCatalogsCommand extends Command
{
    protected $signature = 'catalogs:validate';

    protected $description = 'Valida vigencias, solapamientos y topes de los catálogos fiscales';

    public function handle(ValidateFiscalCatalogs $validator): int
    {
        $issues = $validator->handle();

        if ($issues === []) {
            $this->info(sprintf(
                'Catálogos fiscales válidos (%d filas).',
                TaxRate::query()->count()
                + RetentionRate::query()->count()
                + FormLine::query()->count()
                + PersonalExpenseCap::query()->count(),
            ));

            return self::SUCCESS;
        }

        $this->error(sprintf('Se encontraron %d incidencias en los catálogos fiscales:', count($issues)));

        foreach ($issues as $issue) {
            $this->line(' - '.$issue);
        }

        return self::FAILURE;
    }
}
