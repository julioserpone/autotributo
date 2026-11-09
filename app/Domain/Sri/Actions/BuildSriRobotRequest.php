<?php

declare(strict_types=1);

namespace App\Domain\Sri\Actions;

use App\Domain\Companies\Actions\Credentials\ReadSriCredential;
use App\Domain\Companies\Exceptions\SriCredentialsMissing;
use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Data\SriRobotRequest;
use App\Domain\Sri\Enums\SriDocumentType;

/**
 * Arma la solicitud del robot con las credenciales persistidas de la empresa.
 *
 * Es el puente entre F1-06 y F2-03: `StartSriSync` sigue recibiendo un
 * `SriRobotRequest` ya construido y no conoce el modelo de credenciales. Así el
 * dominio de SRI no depende de Companies más allá de la empresa que ya usa.
 */
final readonly class BuildSriRobotRequest
{
    public function __construct(private ReadSriCredential $readCredential) {}

    /**
     * @param  list<string>  $periods
     * @param  list<string>|null  $documentTypes  `null` = todos los soportados.
     *
     * @throws SriCredentialsMissing
     */
    public function handle(
        Company $company,
        array $periods,
        ?array $documentTypes = null,
        bool $force = false,
        ?int $userId = null,
        ?string $ip = null,
    ): SriRobotRequest {
        $credentials = $this->readCredential->handle($company, userId: $userId, ip: $ip);

        return new SriRobotRequest(
            ruc: $company->ruc,
            periods: $periods,
            documentTypes: $documentTypes ?? SriDocumentType::codes(),
            force: $force,
            credentials: $credentials,
        );
    }
}
