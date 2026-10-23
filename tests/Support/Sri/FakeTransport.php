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

    public function lastResponse(): ?string
    {
        return null;
    }
}
