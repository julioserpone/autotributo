<?php

declare(strict_types=1);

use App\Domain\Sri\Data\AuthorizationQuery;
use App\Domain\Sri\Enums\AuthorizationStatus;
use App\Domain\Sri\Exceptions\SriGatewayException;
use App\Integrations\Sri\PackageSriAuthorizationGateway;
use JulioSerpone\SriComprobantes\Client\AutorizacionClient;
use JulioSerpone\SriComprobantes\Exception\TransportException;
use JulioSerpone\SriComprobantes\Retry\RetryPolicy;
use Tests\Support\Sri\FakeTransport;

function gatewayWith(FakeTransport $transport): PackageSriAuthorizationGateway
{
    return new PackageSriAuthorizationGateway(
        new AutorizacionClient($transport),
        new RetryPolicy(maxAttempts: 1),
    );
}

it('normaliza una autorización y conserva el XML', function (): void {
    $key = str_repeat('1', 49);
    $transport = new FakeTransport([
        'RespuestaAutorizacionComprobante' => [
            'claveAccesoConsultada' => $key,
            'numeroComprobantes' => '1',
            'autorizaciones' => [
                'autorizacion' => [
                    'estado' => 'AUTORIZADO',
                    'numeroAutorizacion' => 'AUT-001',
                    'fechaAutorizacion' => '2026-10-20T10:00:00.000-05:00',
                    'ambiente' => 'PRUEBAS',
                    'comprobante' => '<factura/>',
                    'mensajes' => [
                        'mensaje' => [
                            'identificador' => '43',
                            'mensaje' => 'Comprobante autorizado.',
                            'tipo' => 'ADVERTENCIA',
                        ],
                    ],
                ],
            ],
        ],
    ]);

    $result = gatewayWith($transport)->query(new AuthorizationQuery($key));

    expect($result->status)->toBe(AuthorizationStatus::Authorized)
        ->and($result->authorizationNumber)->toBe('AUT-001')
        ->and($result->authorizedAt?->format(DATE_ATOM))->toBe('2026-10-20T10:00:00-05:00')
        ->and($result->environment)->toBe('PRUEBAS')
        ->and($result->xml)->toBe('<factura/>')
        ->and($result->sriStatus)->toBe('AUTORIZADO')
        ->and($result->messages)->toHaveCount(1)
        ->and($result->messages[0]->isWarning())->toBeTrue();

    expect($transport->calls)->toBe([
        ['operation' => 'autorizacionComprobante', 'arguments' => ['claveAccesoComprobante' => $key]],
    ]);
});

it('traduce un comprobante rechazado sin confundirlo con error de transporte', function (): void {
    $key = str_repeat('2', 49);
    $transport = new FakeTransport([
        'RespuestaAutorizacionComprobante' => [
            'claveAccesoConsultada' => $key,
            'numeroComprobantes' => '1',
            'autorizaciones' => [
                'autorizacion' => [
                    'estado' => 'RECHAZADO',
                    'mensajes' => null,
                ],
            ],
        ],
    ]);

    $result = gatewayWith($transport)->query(new AuthorizationQuery($key));

    expect($result->status)->toBe(AuthorizationStatus::Rejected)
        ->and($result->isRejected())->toBeTrue()
        ->and($result->xml)->toBeNull();
});

it('marca PPR como procesamiento y reintentable', function (): void {
    $key = str_repeat('3', 49);
    $transport = new FakeTransport([
        'RespuestaAutorizacionComprobante' => [
            'claveAccesoConsultada' => $key,
            'numeroComprobantes' => '1',
            'autorizaciones' => [
                'autorizacion' => ['estado' => 'PPR'],
            ],
        ],
    ]);

    $result = gatewayWith($transport)->query(new AuthorizationQuery($key));

    expect($result->status)->toBe(AuthorizationStatus::Processing)
        ->and($result->status->isRetryable())->toBeTrue();
});

it('devuelve no encontrado cuando el SRI no entrega autorizaciones', function (): void {
    $key = str_repeat('4', 49);
    $transport = new FakeTransport([
        'RespuestaAutorizacionComprobante' => [
            'claveAccesoConsultada' => $key,
            'numeroComprobantes' => '0',
            'autorizaciones' => null,
        ],
    ]);

    $result = gatewayWith($transport)->query(new AuthorizationQuery($key));

    expect($result->status)->toBe(AuthorizationStatus::NotFound)
        ->and($result->sriStatus)->toBe('SIN_RESPUESTA')
        ->and($result->hasXml())->toBeFalse();
});

it('envuelve un fallo de transporte en una excepción de dominio', function (): void {
    $transport = new FakeTransport(exception: new TransportException('SOAP caído'));

    expect(fn () => gatewayWith($transport)->query(new AuthorizationQuery(str_repeat('5', 49))))
        ->toThrow(SriGatewayException::class, 'No fue posible consultar');
});
