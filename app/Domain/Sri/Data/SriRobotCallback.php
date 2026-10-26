<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

/**
 * Sobre validado de un callback del robot.
 *
 * Se construye solo después de verificar la firma HMAC y la ventana temporal;
 * el controlador nunca lee cabeceras sin pasar por aquí.
 */
final readonly class SriRobotCallback
{
    /**
     * @param  list<string>  $periods
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $eventId,
        public int $timestamp,
        public string $status,
        public ?string $requestId,
        public ?string $ruc,
        public array $periods,
        public ?string $error,
        public array $payload,
    ) {}

    public function isFailure(): bool
    {
        return $this->status === 'failed';
    }
}
