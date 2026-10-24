<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

use InvalidArgumentException;

/**
 * Credenciales del portal que se sellan antes de invocar al robot.
 *
 * La contraseña queda en una propiedad privada y solo se expone al sellador.
 * El objeto no se serializa ni se registra; `__debugInfo` la redacta para que
 * un `var_dump` accidental no la filtre.
 */
final readonly class SriRobotCredentials
{
    public function __construct(
        public string $ruc,
        #[\SensitiveParameter] private string $password,
        public string $additionalKey = '',
        public ?int $issuedAt = null,
    ) {
        if (preg_match('/^\d{10}(\d{3})?$/', $this->ruc) !== 1) {
            throw new InvalidArgumentException('El RUC de la credencial debe tener 10 o 13 dígitos.');
        }

        if ($password === '') {
            throw new InvalidArgumentException('La contraseña del portal no puede estar vacía.');
        }

        if ($this->issuedAt !== null && $this->issuedAt <= 0) {
            throw new InvalidArgumentException('La marca de tiempo de la credencial no es válida.');
        }
    }

    /**
     * Contenido en claro que el sellador cifra con la clave pública.
     *
     * No use este arreglo para logs, respuestas HTTP ni colas.
     *
     * @return array{ruc: string, password: string, claveAdicional: string, iat: int}
     */
    public function toSealingPayload(): array
    {
        return [
            'ruc' => $this->ruc,
            'password' => $this->password,
            'claveAdicional' => $this->additionalKey,
            'iat' => $this->issuedAt ?? time(),
        ];
    }

    /**
     * @return array{ruc: string, password: string, claveAdicional: string, iat: int|null}
     */
    public function __debugInfo(): array
    {
        return [
            'ruc' => $this->ruc,
            'password' => '***',
            'claveAdicional' => $this->additionalKey === '' ? '' : '***',
            'iat' => $this->issuedAt,
        ];
    }
}
