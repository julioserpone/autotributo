<?php

declare(strict_types=1);

namespace Tests\Support\Sri;

use JulioSerpone\SriComprobantes\Transport\Transport;
use Throwable;

/**
 * Transporte SOAP falso para probar el adaptador sin red.
 */
final class FakeTransport implements Transport
{
    /** @var list<array{operation: string, arguments: array<string, mixed>}> */
    public array $calls = [];

    public function __construct(
        private readonly mixed $response = null,
        private readonly ?Throwable $exception = null,
    ) {}

    private ?string $raw = null;

    public function withRawResponse(string $raw): self
    {
        $this->raw = $raw;

        return $this;
    }

    /**
     * Cuerpo crudo de la última respuesta. Es responsabilidad del transporte
     * exponerlo; el gateway lo usa si su cliente devuelve un valor inesperado.
     */
    public function lastResponse(): string
    {
        $raw = $this->raw;

        if ($raw !== null) {
            return $raw;
        }

        if (is_string($this->response)) {
            return $this->response;
        }

        return is_array($this->response) ? (string) json_encode($this->response) : '';
    }

    public function call(string $operation, array $arguments): mixed
    {
        $this->calls[] = ['operation' => $operation, 'arguments' => $arguments];

        if ($this->exception instanceof Throwable) {
            throw $this->exception;
        }

        return $this->response;
    }

    public function lastRequest(): ?string
    {
        return null;
    }
}
