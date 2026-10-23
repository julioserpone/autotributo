<?php

declare(strict_types=1);

namespace App\Domain\Sri\Data;

use App\Domain\Sri\Enums\AuthorizationStatus;
use DateTimeImmutable;

/**
 * Resultado normalizado de consultar la autorización de un comprobante.
 *
 * El XML autorizado se conserva tal como lo entrega el SRI; la persistencia y
 * el cifrado en S3 son responsabilidad de la capa que use este resultado.
 */
final readonly class AuthorizationResult
{
    /**
     * @param  list<SriMessage>  $messages
     */
    public function __construct(
        public string $accessKey,
        public AuthorizationStatus $status,
        public ?string $authorizationNumber = null,
        public ?DateTimeImmutable $authorizedAt = null,
        public ?string $environment = null,
        public ?string $xml = null,
        public array $messages = [],
        public ?string $sriStatus = null,
    ) {}

    public static function notFound(string $accessKey, ?string $sriStatus = null): self
    {
        return new self(
            accessKey: $accessKey,
            status: AuthorizationStatus::NotFound,
            sriStatus: $sriStatus,
        );
    }

    public function isAuthorized(): bool
    {
        return $this->status === AuthorizationStatus::Authorized;
    }

    public function isRejected(): bool
    {
        return $this->status === AuthorizationStatus::Rejected;
    }

    public function isProcessing(): bool
    {
        return $this->status === AuthorizationStatus::Processing;
    }

    public function hasXml(): bool
    {
        return $this->xml !== null && $this->xml !== '';
    }
}
