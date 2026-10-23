<?php

declare(strict_types=1);

use App\Domain\Sri\Data\SriRobotRequest;
use App\Domain\Sri\Exceptions\SriGatewayException;
use App\Integrations\Sri\LambdaSriRobotGateway;
use Aws\Command;
use Aws\Exception\AwsException;
use Aws\Lambda\LambdaClient;
use Aws\Result;

afterEach(function (): void {
    Mockery::close();
});

it('invoca la Lambda con el payload del robot y modo asíncrono', function (): void {
    $lambda = Mockery::mock(LambdaClient::class);
    $lambda->shouldReceive('invoke')
        ->once()
        ->withArgs(function (array $arguments): bool {
            expect($arguments['InvocationType'])->toBe('Event')
                ->and($arguments['FunctionName'])->toBe('sri-txt-robot')
                ->and(json_decode((string) $arguments['Payload'], true))->toBe([
                    'ruc' => '1758871873001',
                    'periodos' => ['2026-08'],
                    'tipos' => ['01'],
                    'forzar' => false,
                ]);

            return true;
        })
        ->andReturn(new Result([
            'StatusCode' => 202,
            'ResponseMetadata' => ['RequestId' => 'req-123'],
        ]));

    $dispatch = (new LambdaSriRobotGateway($lambda, 'sri-txt-robot'))
        ->dispatch(new SriRobotRequest('1758871873001', ['2026-08'], ['01']));

    expect($dispatch->isAccepted())->toBeTrue()
        ->and($dispatch->requestId)->toBe('req-123')
        ->and($dispatch->statusCode)->toBe(202);
});

it('envuelve los errores de AWS en una excepción de dominio', function (): void {
    $lambda = Mockery::mock(LambdaClient::class);
    $lambda->shouldReceive('invoke')
        ->once()
        ->andThrow(new AwsException('Lambda no disponible', new Command('invoke')));

    expect(fn () => (new LambdaSriRobotGateway($lambda, 'sri-txt-robot'))
        ->dispatch(new SriRobotRequest('1758871873001', ['2026-08'])))
        ->toThrow(SriGatewayException::class, 'No fue posible invocar');
});
