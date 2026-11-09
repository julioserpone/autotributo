<?php

declare(strict_types=1);

namespace App\Domain\Companies\Actions;

use App\Domain\Companies\Models\Company;

/**
 * Archiva una empresa sin borrar su historial.
 *
 * Marca el estado como `archived` y aplica soft delete. La revocación de
 * credenciales y la detención de sincronizaciones se enganchan en F1-06 y
 * F2-14 respectivamente.
 */
final readonly class ArchiveCompany
{
    public function handle(Company $company): void
    {
        $company->forceFill(['status' => Company::STATUS_ARCHIVED])->save();
        $company->delete();
    }
}
