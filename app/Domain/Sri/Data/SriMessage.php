<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

/**
 * Mensaje de error o advertencia devuelto por el SRI.
 */
final readonly class SriMessage
{
    public function __construct(
        public ?string $code = null,
        public ?string $message = null,
        public ?string $additionalInformation = null,
        public ?string $type = null,
    ) {}

    public function isError(): bool
    {
        return strtoupper((string) $this->type) === 'ERROR';
    }

    public function isWarning(): bool
    {
        return strtoupper((string) $this->type) === 'ADVERTENCIA';
    }
}
