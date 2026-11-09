<?php

declare(strict_types=1);

namespace App\Domain\Companies\Actions\Credentials;

use App\Domain\Companies\Exceptions\SriCredentialsMissing;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\SriCredential;
use App\Domain\Companies\Models\SriCredentialAccess;
use App\Support\Crypto\SriCredentialCipher;
use Illuminate\Support\Facades\DB;

/**
 * Rota la contraseña de la credencial sin tocar estado ni fechas de uso.
 *
 * A diferencia de `StoreSriCredential`, falla si no existe credencial: rotar
 * es una acción explícita del contador para reemplazar un secreto vigente.
 */
final readonly class RotateSriCredential
{
    public function __construct(private SriCredentialCipher $cipher) {}

    public function handle(
        Company $company,
        #[\SensitiveParameter] string $password,
        ?int $userId = null,
        ?string $ip = null,
    ): SriCredential {
        return DB::transaction(function () use ($company, $password, $userId, $ip): SriCredential {
            $credential = SriCredential::query()
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->first();

            if (! $credential instanceof SriCredential) {
                throw SriCredentialsMissing::forCompany((string) $company->public_id);
            }

            $credential->forceFill([
                'password_ciphertext' => $this->cipher->encrypt($password),
                'key_version' => $this->cipher->keyVersion(),
                'status' => SriCredential::STATUS_ACTIVE,
                'failure_count' => 0,
                'rotated_at' => now(),
            ])->save();

            SriCredentialAccess::query()->create([
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'user_id' => $userId,
                'purpose' => SriCredentialAccess::PURPOSE_ROTATION,
                'ip' => $ip,
            ]);

            return $credential;
        });
    }
}
