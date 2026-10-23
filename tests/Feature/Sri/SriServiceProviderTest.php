<?php

declare(strict_types=1);

use App\Domain\Sri\Contracts\SriAuthorizationGateway;
use App\Domain\Sri\Contracts\SriRobotGateway;
use App\Integrations\Sri\FakeSriAuthorizationGateway;
use App\Integrations\Sri\FakeSriRobotGateway;
use App\Integrations\Sri\LambdaSriRobotGateway;
use App\Integrations\Sri\PackageSriAuthorizationGateway;

it('resuelve adaptadores fake para desarrollo y pruebas', function (): void {
    config([
        'sri.authorization.driver' => 'fake',
        'sri.robot.driver' => 'fake',
    ]);

    expect(app(SriAuthorizationGateway::class))->toBeInstanceOf(FakeSriAuthorizationGateway::class)
        ->and(app(SriRobotGateway::class))->toBeInstanceOf(FakeSriRobotGateway::class);
});

it('resuelve el paquete SOAP y la Lambda reales', function (): void {
    config([
        'sri.authorization.driver' => 'package',
        'sri.robot.driver' => 'lambda',
        'sri.robot.function' => 'sri-txt-robot',
        'sri.robot.region' => 'us-east-1',
    ]);

    expect(app(SriAuthorizationGateway::class))->toBeInstanceOf(PackageSriAuthorizationGateway::class)
        ->and(app(SriRobotGateway::class))->toBeInstanceOf(LambdaSriRobotGateway::class);
});

it('falla de forma explícita con un driver desconocido', function (): void {
    config(['sri.authorization.driver' => 'desconocido']);

    expect(fn () => app(SriAuthorizationGateway::class))
        ->toThrow(InvalidArgumentException::class, 'no soportado');
});
