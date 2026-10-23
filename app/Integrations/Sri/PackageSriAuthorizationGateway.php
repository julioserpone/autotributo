<?php

declare(strict_types=1);

namespace App\Integrations\Sri;

use App\Domain\Sri\Contracts\SriAuthorizationGateway;
use App\Domain\Sri\Data\AuthorizationQuery;
use App\Domain\Sri\Data\AuthorizationResult;
use App\Domain\Sri\Data\SriMessage;
use App\Domain\Sri\Enums\AuthorizationStatus;
use App\Domain\Sri\Exceptions\SriGatewayException;
use JulioSerpone\SriComprobantes\Client\AutorizacionClient;
use JulioSerpone\SriComprobantes\Dto\Autorizacion;
use JulioSerpone\SriComprobantes\Dto\Mensaje;
use JulioSerpone\SriComprobantes\Retry\RetryPolicy;
use Throwable;

/**
 * Adaptador del paquete `julioserpone/sri-comprobantes-php` al puerto del
 * dominio. Traduce DTOs del paquete y aplica reintentos solo de transporte.
 */
final readonly class PackageSriAuthorizationGateway implements SriAuthorizationGateway
{
    public function __construct(
        private AutorizacionClient $client,
        private RetryPolicy $retry,
    ) {}

    public function query(AuthorizationQuery $query): AuthorizationResult
    {
        try {
            $response = $this->retry->execute(
                fn () => $this->client->consultar($query->accessKey),
            );
        } catch (Throwable $exception) {
            throw SriGatewayException::authorizationUnavailable($exception);
        }

        $authorization = $response->first();

        if (! $authorization instanceof Autorizacion) {
            return AuthorizationResult::notFound($query->accessKey, $response->estado());
        }

        $status = match (true) {
            $authorization->isAutorizado() => AuthorizationStatus::Authorized,
            $authorization->isRechazado() => AuthorizationStatus::Rejected,
            $response->isEnProceso() => AuthorizationStatus::Processing,
            default => AuthorizationStatus::Unknown,
        };

        return new AuthorizationResult(
            accessKey: $query->accessKey,
            status: $status,
            authorizationNumber: $authorization->numeroAutorizacion,
            authorizedAt: $authorization->fechaAutorizacion,
            environment: $authorization->ambiente,
            xml: $authorization->comprobante === '' ? null : $authorization->comprobante,
            messages: array_map(
                static fn (Mensaje $message): SriMessage => new SriMessage(
                    code: $message->identificador,
                    message: $message->mensaje,
                    additionalInformation: $message->informacionAdicional,
                    type: $message->tipo,
                ),
                $authorization->mensajes,
            ),
            sriStatus: $authorization->estado,
        );
    }
}
