<?php

declare(strict_types=1);

namespace App\Domain\Companies\Actions\Credentials;

use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\SriCredential;
use App\Domain\Companies\Models\SriCredentialAccess;
use Illuminate\Support\Facades\DB;

/**
 * Elimina físicamente la credencial de una empresa.
 *
 * Se invoca al archivar la empresa (política de retención de docs/03) y desde
 * el endpoint `DELETE`. No aborta si hay un run en curso: el run continúa y el
 * siguiente intento de sync fallará con `SriCredentialsMissing`.
 *
 * El borrado se registra como auditoría (`credential_access`, propósito
 * `rotation`) en lugar de como fila de acceso: la tabla de accesos referencia
 * la empresa, no la credencial, y debe conservar el rastro de la eliminación.
 */
final readonly class DeleteSriCredential
{
    public function handle(Company $company, ?int $userId = null, ?string $ip = null): bool
    {
        return DB::transaction(function () use ($company, $userId, $ip): bool {
            $deleted = SriCredential::query()
                ->where('company_id', $company->id)
                ->delete() > 0;

            if (! $deleted) {
                return false;
            }

            SriCredentialAccess::query()->create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'user_id' => $userId,
                'purpose' => SriCredentialAccess::PURPOSE_ROTATION,
                'ip' => $ip,
            ]);

            return true;
        });
    }
}
