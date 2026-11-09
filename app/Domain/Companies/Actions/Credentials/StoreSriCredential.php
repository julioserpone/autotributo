<?php

declare(strict_types=1);

namespace App\Domain\Companies\Actions\Credentials;

use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\SriCredential;
use App\Domain\Companies\Models\SriCredentialAccess;
use App\Support\Crypto\SriCredentialCipher;
use Illuminate\Support\Facades\DB;

/**
 * Crea o reemplaza la credencial del portal de una empresa.
 *
 * Es idempotente por `company_id`: si ya existe una credencial se sobreescribe
 * el secreto (rotación) y se reinicia el contador de fallos, porque un nuevo
 * intento con datos válidos debe desbloquear la cuenta.
 */
final readonly class StoreSriCredential
{
    public function __construct(private SriCredentialCipher $cipher) {}

    public function handle(
        Company $company,
        string $username,
        #[\SensitiveParameter] string $password,
        ?int $userId = null,
        ?string $ip = null,
    ): SriCredential {
        return DB::transaction(function () use ($company, $username, $password, $userId, $ip): SriCredential {
            $credential = SriCredential::query()
                ->where('company_id', $company->id)
                ->lockForUpdate()
                ->first();

            $existed = $credential instanceof SriCredential;

            $attributes = [
                'tenant_id' => $company->tenant_id,
                'company_id' => $company->id,
                'username' => $username,
                'password_ciphertext' => $this->cipher->encrypt($password),
                'key_version' => $this->cipher->keyVersion(),
                'status' => SriCredential::STATUS_ACTIVE,
                'failure_count' => 0,
                'rotated_at' => now(),
            ];

            if ($existed) {
                $credential->forceFill($attributes)->save();
            } else {
                $credential = SriCredential::query()->create([
                    ...$attributes,
                    'created_by' => $userId,
                ]);
            }

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
