<?php

declare(strict_types=1);

namespace App\Domain\Companies\Actions;

use App\Domain\Companies\Actions\Credentials\DeleteSriCredential;
use App\Domain\Companies\Models\Company;
use Illuminate\Support\Facades\DB;

/**
 * Archiva una empresa sin borrar su historial.
 *
 * Marca el estado como `archived`, aplica soft delete y elimina la credencial
 * del portal: mientras la empresa esté archivada no debe operar contra el SRI
 * ni conservar el secreto del contribuyente (docs/03 §13). La detención de
 * sincronizaciones en curso queda para F2-14.
 */
final readonly class ArchiveCompany
{
    public function __construct(private DeleteSriCredential $deleteCredential) {}

    public function handle(Company $company): void
    {
        DB::transaction(function () use ($company): void {
            $this->deleteCredential->handle($company);

            $company->forceFill(['status' => Company::STATUS_ARCHIVED])->save();
            $company->delete();
        });
    }
}
