<?php

declare(strict_types=1);

namespace App\Domain\Companies\Actions\Credentials;

use App\Domain\Companies\Exceptions\SriCredentialDecryptionFailed;
use App\Domain\Companies\Exceptions\SriCredentialsMissing;
use App\Domain\Companies\Exceptions\SriCredentialUnavailable;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\SriCredential;
use App\Domain\Companies\Models\SriCredentialAccess;
use App\Domain\Sri\Data\SriRobotCredentials;
use App\Support\Crypto\SriCredentialCipher;
use RuntimeException;

/**
 * Único punto del sistema que descifra la contraseña del portal.
 *
 * Cada lectura registra `sri_credential_accesses` **antes** de devolver el
 * secreto: si el registro falla, no se entrega nada. Así el audit trail no
 * depende de que el llamador se acuerde de escribir.
 */
final readonly class ReadSriCredential
{
    public function __construct(private SriCredentialCipher $cipher) {}

    public function handle(
        Company $company,
        string $purpose = SriCredentialAccess::PURPOSE_SYNC,
        ?int $userId = null,
        ?string $ip = null,
    ): SriRobotCredentials {
        $credential = SriCredential::query()
            ->where('company_id', $company->id)
            ->first();

        if (! $credential instanceof SriCredential) {
            throw SriCredentialsMissing::forCompany((string) $company->public_id);
        }

        if (! $credential->isActive()) {
            throw SriCredentialUnavailable::invalidStatus(
                (string) $company->public_id,
                $credential->status,
            );
        }

        // Se registra antes de descifrar: el acceso se audita aunque el blob
        // falle después (p. ej. una rotación de clave pendiente).
        SriCredentialAccess::query()->create([
            'tenant_id' => $company->tenant_id,
            'company_id' => $company->id,
            'user_id' => $userId,
            'purpose' => $purpose,
            'ip' => $ip,
        ]);

        try {
            $password = $this->cipher->decrypt($credential->password_ciphertext);
        } catch (RuntimeException $exception) {
            // El detalle (versión de clave) va al log de aplicación sin PII;
            // al llamador se le da un 409 accionable.
            report(SriCredentialDecryptionFailed::forCompany(
                $company->id,
                $this->cipher->keyVersion(),
                $exception->getMessage(),
            ));

            throw SriCredentialUnavailable::notDecryptable((string) $company->public_id);
        }

        $credential->forceFill(['last_used_at' => now()])->save();

        return new SriRobotCredentials($credential->username, $password);
    }
}
