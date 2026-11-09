<?php

declare(strict_types=1);

namespace App\Domain\Companies\Data;

use App\Domain\Companies\Models\SriCredential;

/**
 * Vista de la credencial con su estado operativo.
 *
 * No transporta el secreto ni el blob: solo lo que la UI y el monitor
 * necesitan para decidir si la empresa puede sincronizar.
 */
final readonly class SriCredentialSnapshot
{
    public function __construct(
        public bool $exists,
        public ?string $publicId = null,
        public ?string $username = null,
        public ?string $status = null,
        public int $failureCount = 0,
        public ?string $lastUsedAt = null,
        public ?string $lastSuccessAt = null,
        public ?string $lastFailureAt = null,
        public ?string $rotatedAt = null,
    ) {}

    public static function fromModel(SriCredential $credential): self
    {
        return new self(
            exists: true,
            publicId: $credential->public_id,
            username: $credential->username,
            status: $credential->status,
            failureCount: $credential->failure_count,
            lastUsedAt: $credential->last_used_at?->toIso8601String(),
            lastSuccessAt: $credential->last_success_at?->toIso8601String(),
            lastFailureAt: $credential->last_failure_at?->toIso8601String(),
            rotatedAt: $credential->rotated_at?->toIso8601String(),
        );
    }

    public static function none(): self
    {
        return new self(exists: false);
    }
}
