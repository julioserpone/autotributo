<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

/**
 * Confirmación de la invocación asíncrona del robot.
 */
final readonly class SriRobotDispatch
{
    public function __construct(
        public string $functionName,
        public int $statusCode,
        public ?string $requestId = null,
    ) {}

    public function isAccepted(): bool
    {
        return $this->statusCode >= 200 && $this->statusCode < 300;
    }
}
