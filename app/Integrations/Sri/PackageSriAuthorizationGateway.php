<?php

declare(strict_types=1);

namespace App\Integrations\Sri;

use App\Domain\Sri\Contracts\SriAuthorizationGateway;
use App\Domain\Sri\Data\AuthorizationQuery;
use App\Domain\Sri\Data\AuthorizationResult;
use App\Domain\Sri\Data\SriMessage;
use App\Domain\Sri\Enums\AuthorizationStatus;
use App\Domain\Sri\Exceptions\SriGatewayException;
use DOMDocument;
use DOMNode;
use JulioSerpone\SriComprobantes\Client\AutorizacionClient;
use JulioSerpone\SriComprobantes\Dto\Autorizacion;
use JulioSerpone\SriComprobantes\Dto\AutorizacionResult;
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
        $raw = '';

        try {
            $response = $this->retry->execute(function () use ($query, &$raw) {
                try {
                    $response = $this->client->consultar($query->accessKey);
                } finally {
                    // Aunque el paquete no pueda interpretar la respuesta, el
                    // cuerpo crudo del transporte queda disponible para el
                    // respaldo de abajo.
                    $raw = $this->toXml(null);
                }

                return $response;
            });
        } catch (Throwable $exception) {
            // Un `soap:Envelope` completo no es lo que `AutorizacionResult`
            // espera: el paquete puede lanzar con una respuesta válida. Si el
            // cuerpo crudo trae la autorización, se usa en lugar de fallar.
            $unwrapped = $this->unwrap($raw);

            if ($unwrapped === null) {
                throw SriGatewayException::authorizationUnavailable($exception);
            }

            return $this->map($query->accessKey, AutorizacionResult::fromArray($unwrapped));
        }

        // El paquete normalmente entrega el `RespuestaAutorizacionComprobante`
        // ya desenvuelto. Si llega el sobre SOAP o un tipo inesperado, se
        // normaliza aquí con el cuerpo crudo para no perder el comprobante.
        if ($response instanceof AutorizacionResult) {
            return $this->map($query->accessKey, $response);
        }

        if (is_array($response)) {
            return $this->map($query->accessKey, AutorizacionResult::fromArray($response));
        }

        $unwrapped = $raw === '' ? null : $this->unwrap($raw);

        if ($unwrapped === null) {
            throw SriGatewayException::robotObjectUnreadable();
        }

        return $this->map($query->accessKey, AutorizacionResult::fromArray($unwrapped));
    }

    /**
     * Cuerpo XML crudo de la respuesta del paquete, si lo expone.
     */
    private function toXml(mixed $response): string
    {
        try {
            $raw = (string) $this->client->lastResponse();
        } catch (Throwable) {
            $raw = '';
        }

        if (trim($raw) !== '') {
            return $raw;
        }

        return is_string($response) && str_contains($response, '<') ? $response : '';
    }

    private function map(string $accessKey, AutorizacionResult $response): AuthorizationResult
    {
        $authorization = $response->first();

        if (! $authorization instanceof Autorizacion) {
            return AuthorizationResult::notFound($accessKey, $response->estado());
        }

        $status = match (true) {
            $authorization->isAutorizado() => AuthorizationStatus::Authorized,
            $authorization->isRechazado() => AuthorizationStatus::Rejected,
            $response->isEnProceso() => AuthorizationStatus::Processing,
            default => AuthorizationStatus::Unknown,
        };

        return new AuthorizationResult(
            accessKey: $accessKey,
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

    /**
     * @return array<string, mixed>|null
     */
    private function unwrap(string $raw): ?array
    {
        if (trim($raw) === '') {
            return null;
        }

        $document = new DOMDocument;

        try {
            // Sin `LIBXML_NOENT` ni DTD: la respuesta del SRI no debe resolver
            // entidades externas (XXE) ni expandir entidades.
            $loaded = $document->loadXML($raw, LIBXML_NONET);
        } catch (Throwable) {
            return null;
        }

        if (! $loaded) {
            return null;
        }

        foreach (['RespuestaAutorizacionComprobante', 'autorizacionComprobanteResponse'] as $name) {
            $nodes = $document->getElementsByTagNameNS('*', $name);

            if ($nodes->length === 0) {
                continue;
            }

            $node = $nodes->item(0);
            $xml = $node instanceof DOMNode ? $document->saveXML($node) : false;

            if (! is_string($xml)) {
                continue;
            }

            $simple = simplexml_load_string($xml, options: LIBXML_NONET);
            $decoded = $simple === false ? null : json_decode((string) json_encode($simple), true);

            // `simplexml` conserva el namespace de la respuesta; se descarta
            // para que el DTO del paquete encuentre las claves por nombre.
            if (is_array($decoded)) {
                return $this->stripNamespaces($decoded);
            }
        }

        return null;
    }

    /**
     * @param  array<array-key, mixed>  $data
     * @return array<array-key, mixed>
     */
    private function stripNamespaces(array $data): array
    {
        $clean = [];

        foreach ($data as $key => $value) {
            $name = is_string($key) && str_contains($key, ':')
                ? substr($key, (int) strrpos($key, ':') + 1)
                : $key;

            $clean[$name] = is_array($value) ? $this->stripNamespaces($value) : $value;
        }

        return $clean;
    }
}
