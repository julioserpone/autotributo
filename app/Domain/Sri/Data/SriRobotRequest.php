<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

use InvalidArgumentException;

/**
 * Evento que consume la función Lambda `sri-txt-robot`.
 *
 * El contrato real del robot es deliberadamente pequeño: RUC, períodos,
 * tipos de comprobante y la bandera para forzar una descarga. El bucket, las
 * credenciales del portal y el callback viven en la configuración de la
 * función Lambda, no en el payload enviado por Laravel.
 */
final readonly class SriRobotRequest
{
    /** @var list<string> */
    public array $periods;

    /** @var list<string> */
    public array $documentTypes;

    private const ALLOWED_DOCUMENT_TYPES = ['01', '03', '04', '05', '07'];

    /**
     * @param  list<string>  $periods  Formatos YYYY-MM o YYYY-MM-DD.
     * @param  list<string>  $documentTypes  Códigos de la ficha técnica del SRI.
     */
    public function __construct(
        public string $ruc,
        array $periods,
        array $documentTypes = [],
        public bool $force = false,
    ) {
        if (preg_match('/^\d{10}(\d{3})?$/', $this->ruc) !== 1) {
            throw new InvalidArgumentException('El RUC debe tener 10 o 13 dígitos.');
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
            if (! in_array($documentType, self::ALLOWED_DOCUMENT_TYPES, true)) {
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
     * ya implementado por el robot.
     *
     * @return array{ruc: string, periodos: list<string>, tipos: list<string>, forzar: bool}
     */
    public function toPayload(): array
    {
        return [
            'ruc' => $this->ruc,
            'periodos' => $this->periods,
            'tipos' => $this->documentTypes,
            'forzar' => $this->force,
        ];
    }
}
