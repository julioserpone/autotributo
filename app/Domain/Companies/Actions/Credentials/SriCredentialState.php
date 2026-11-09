<?php

declare(strict_types=1);

namespace App\Domain\Companies\Actions\Credentials;

use App\Domain\Companies\Data\SriCredentialSnapshot;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\SriCredential;

/**
 * Consulta el estado de la credencial sin descifrarla ni registrar acceso.
 *
 * A diferencia de `ReadSriCredential`, es seguro para listados y pantallas de
 * configuración: no toca el secreto y, por tanto, no ensucia la auditoría.
 */
final readonly class SriCredentialState
{
    public function handle(Company $company): SriCredentialSnapshot
    {
        $credential = SriCredential::query()
            ->where('company_id', $company->id)
            ->first();

        return $credential instanceof SriCredential
            ? SriCredentialSnapshot::fromModel($credential)
            : SriCredentialSnapshot::none();
    }
}
