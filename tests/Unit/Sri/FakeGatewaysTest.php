<?php

declare(strict_types=1);

use App\Domain\Sri\Data\AuthorizationQuery;
use App\Domain\Sri\Data\AuthorizationResult;
use App\Domain\Sri\Data\SriMessage;
use App\Domain\Sri\Data\SriRobotRequest;
use App\Domain\Sri\Enums\AuthorizationStatus;
use App\Integrations\Sri\FakeSriAuthorizationGateway;
use App\Integrations\Sri\FakeSriRobotGateway;

it('devuelve el fixture configurado y registra la consulta', function (): void {
    $key = str_repeat('6', 49);
    $expected = new AuthorizationResult($key, AuthorizationStatus::Authorized, xml: '<factura/>');
    $gateway = new FakeSriAuthorizationGateway([$key => $expected]);

    $result = $gateway->query(new AuthorizationQuery($key));

    expect($result)->toBe($expected)
        ->and($gateway->queries)->toHaveCount(1)
        ->and($gateway->queries[0]->accessKey)->toBe($key);
});

it('expone las invariantes del resultado y de los estados', function (): void {
    $authorized = new AuthorizationResult(str_repeat('8', 49), AuthorizationStatus::Authorized, xml: '<factura/>');

    expect($authorized->isAuthorized())->toBeTrue()
        ->and($authorized->isRejected())->toBeFalse()
        ->and($authorized->isProcessing())->toBeFalse()
        ->and($authorized->hasXml())->toBeTrue()
        ->and(AuthorizationStatus::Rejected->isFinal())->toBeTrue()
        ->and(AuthorizationStatus::Processing->isRetryable())->toBeTrue()
        ->and(AuthorizationStatus::Unknown->isRetryable())->toBeTrue();

    $error = new SriMessage(type: 'ERROR');
    $warning = new SriMessage(type: 'ADVERTENCIA');

    expect($error->isError())->toBeTrue()
        ->and($warning->isWarning())->toBeTrue();
});

it('rechaza una clave con longitud incorrecta antes de llegar al adaptador', function (): void {
    expect(fn () => new AuthorizationQuery('123'))
        ->toThrow(InvalidArgumentException::class);
});

it('devuelve no encontrado para una clave sin fixture', function (): void {
    $key = str_repeat('7', 49);
    $gateway = new FakeSriAuthorizationGateway;

    expect($gateway->query(new AuthorizationQuery($key))->status)
        ->toBe(AuthorizationStatus::NotFound);
});

it('registra el despacho del robot fake', function (): void {
    $gateway = new FakeSriRobotGateway;
    $request = new SriRobotRequest('1758871873001', ['2026-08'], ['01']);

    $dispatch = $gateway->dispatch($request);

    expect($gateway->requests)->toBe([$request])
        ->and($dispatch->isAccepted())->toBeTrue()
        ->and($dispatch->functionName)->toBe('fake-sri-txt-robot');
});
