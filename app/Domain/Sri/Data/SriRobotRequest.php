<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

use App\Domain\Sri\Enums\SriDocumentType;
use InvalidArgumentException;

/**
 * Evento que consume la función Lambda `sri-txt-robot`.
 *
 * El contrato real del robot es deliberadamente pequeño: RUC, períodos,
 * tipos de comprobante, la bandera para forzar una descarga y, cuando el
 * robot opera en modo dinámico, un sobre con las credenciales del portal.
 * El sobre se cifra con la clave pública del robot; la contraseña nunca viaja
 * en claro. El bucket y el callback viven en la configuración de la función
 * Lambda.
 */
final readonly class SriRobotRequest
{
    /** @var list<string> */
    public array $periods;

    /** @var list<string> */
    public array $documentTypes;

    /**
     * @param  list<string>  $periods  Formatos YYYY-MM o YYYY-MM-DD.
     * @param  list<string>  $documentTypes  Códigos de la ficha técnica del SRI.
     */
    public function __construct(
        public string $ruc,
        array $periods,
        array $documentTypes = [],
        public bool $force = false,
        public ?SriRobotCredentials $credentials = null,
    ) {
        if (preg_match('/^\d{10}(\d{3})?$/', $this->ruc) !== 1) {
            throw new InvalidArgumentException('El RUC debe tener 10 o 13 dígitos.');
        }

        if ($this->credentials !== null && $this->credentials->ruc !== $this->ruc) {
            throw new InvalidArgumentException('El RUC de las credenciales no coincide con la solicitud.');
        }

        $periods = array_values(array_unique($periods));

        if ($periods === []) {
            throw new InvalidArgumentException('La solicitud debe incluir al menos un período.');
        }

        foreach ($periods as $period) {
            if (preg_match('/^\d{4}-(0[1-9]|1[0-2])(-(0[1-9]|[12]\d|3[01]))?$/', $period) !== 1) {
                throw new InvalidArgumentException("Período inválido: {$period}.");
            }
        }

        $documentTypes = array_values(array_unique($documentTypes));

        foreach ($documentTypes as $documentType) {
            if (SriDocumentType::tryFrom($documentType) === null) {
                throw new InvalidArgumentException("Tipo de comprobante no soportado: {$documentType}.");
            }
        }

        $this->periods = $periods;
        $this->documentTypes = $documentTypes;
    }

    /**
     * Payload exacto que espera `sri_txt_robot.handler`.
     *
     * Las claves están en español porque forman parte del contrato externo
     * ya implementado por el robot. Si la solicitud lleva credenciales, el
     * llamador debe entregar el sobre ya sellado; así no se puede construir
     * por accidente un payload sin credenciales en modo dinámico.
     *
     * @return array{ruc: string, periodos: list<string>, tipos: list<string>, forzar: bool, sealed_credentials?: string}
     */
    public function toPayload(?string $sealedCredentials = null): array
    {
        if ($this->credentials !== null && $sealedCredentials === null) {
            throw new InvalidArgumentException('Las credenciales deben sellarse antes de construir el payload.');
        }

        if ($sealedCredentials !== null && trim($sealedCredentials) === '') {
            throw new InvalidArgumentException('El sobre de credenciales no puede estar vacío.');
        }

        $payload = [
            'ruc' => $this->ruc,
            'periodos' => $this->periods,
            'tipos' => $this->documentTypes,
            'forzar' => $this->force,
        ];

        if ($sealedCredentials !== null) {
            $payload['sealed_credentials'] = $sealedCredentials;
        }

        return $payload;
    }
}
