<?php

declare(strict_types=1);

use App\Domain\Sri\Data\SriRobotCredentials;
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
            $payload = json_decode((string) $arguments['Payload'], true, flags: JSON_THROW_ON_ERROR);

            expect($arguments['InvocationType'])->toBe('Event')
                ->and($arguments['FunctionName'])->toBe('sri-txt-robot')
                ->and($payload)->toMatchArray([
                    'ruc' => '1758871873001',
                    'periodos' => ['2026-08'],
                    'tipos' => ['01'],
                    'forzar' => false,
                ])
                ->and($payload['event_id'])->toBeString()->toHaveLength(26)
                ->and($payload)->not->toHaveKey('callback_url');

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
        ->and($dispatch->eventId)->toBeString()->toHaveLength(26)
        ->and($dispatch->statusCode)->toBe(202);
});

it('incluye la URL de callback y el mismo event_id de la invocación', function (): void {
    $lambda = Mockery::mock(LambdaClient::class);
    $capturado = null;

    $lambda->shouldReceive('invoke')
        ->once()
        ->withArgs(function (array $arguments) use (&$capturado): bool {
            $capturado = json_decode((string) $arguments['Payload'], true, flags: JSON_THROW_ON_ERROR);

            return true;
        })
        ->andReturn(new Result([
            'StatusCode' => 202,
            'ResponseMetadata' => ['RequestId' => 'req-callback'],
        ]));

    $dispatch = (new LambdaSriRobotGateway(
        $lambda,
        'sri-txt-robot',
        null,
        'https://app.ejemplo.test/api/v1/webhooks/sri-robot',
    ))->dispatch(new SriRobotRequest('1790012345001', ['2026-08']));

    expect($capturado)->toBeArray()
        ->and($capturado['callback_url'])->toBe('https://app.ejemplo.test/api/v1/webhooks/sri-robot')
        ->and($capturado['event_id'])->toBe($dispatch->eventId);
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

it('sella las credenciales y las envía sin contraseña en claro', function (): void {
    $keypair = sodium_crypto_box_keypair();
    $publicKey = base64_encode(sodium_crypto_box_publickey($keypair));
    $lambda = Mockery::mock(LambdaClient::class);

    $lambda->shouldReceive('invoke')
        ->once()
        ->withArgs(function (array $arguments) use ($keypair): bool {
            $payload = json_decode((string) $arguments['Payload'], true, flags: JSON_THROW_ON_ERROR);

            expect($payload)->not->toHaveKey('password')
                ->and($payload['sealed_credentials'])->toBeString();

            $sealed = base64_decode((string) $payload['sealed_credentials'], true);
            expect($sealed)->not->toBeFalse();

            $plaintext = sodium_crypto_box_seal_open((string) $sealed, $keypair);
            expect($plaintext)->not->toBeFalse();

            expect(json_decode((string) $plaintext, true, flags: JSON_THROW_ON_ERROR))->toBe([
                'ruc' => '1758871873001',
                'password' => 'clave-real',
                'claveAdicional' => '',
                'iat' => 1_760_000_000,
            ]);

            return true;
        })
        ->andReturn(new Result([
            'StatusCode' => 202,
            'ResponseMetadata' => ['RequestId' => 'req-sellada'],
        ]));

    $request = new SriRobotRequest(
        ruc: '1758871873001',
        periods: ['2026-08'],
        documentTypes: ['01'],
        credentials: new SriRobotCredentials(
            ruc: '1758871873001',
            password: 'clave-real',
            issuedAt: 1_760_000_000,
        ),
    );

    $dispatch = (new LambdaSriRobotGateway($lambda, 'sri-txt-robot', $publicKey))
        ->dispatch($request);

    expect($dispatch->isAccepted())->toBeTrue()
        ->and($dispatch->requestId)->toBe('req-sellada');
});

it('acepta la clave pública con el prefijo base64 de Laravel', function (): void {
    $keypair = sodium_crypto_box_keypair();
    $publicKey = 'base64:'.base64_encode(sodium_crypto_box_publickey($keypair));
    $lambda = Mockery::mock(LambdaClient::class);

    $lambda->shouldReceive('invoke')
        ->once()
        ->andReturn(new Result([
            'StatusCode' => 202,
            'ResponseMetadata' => ['RequestId' => 'req-prefijo'],
        ]));

    $request = new SriRobotRequest(
        ruc: '1758871873001',
        periods: ['2026-08'],
        credentials: new SriRobotCredentials('1758871873001', 'clave-real'),
    );

    expect((new LambdaSriRobotGateway($lambda, 'sri-txt-robot', $publicKey))
        ->dispatch($request)->isAccepted())->toBeTrue();
});

it('no invoca la Lambda si hay credenciales pero falta la clave pública', function (): void {
    $lambda = Mockery::mock(LambdaClient::class);
    $lambda->shouldNotReceive('invoke');

    $request = new SriRobotRequest(
        ruc: '1758871873001',
        periods: ['2026-08'],
        credentials: new SriRobotCredentials('1758871873001', 'clave-real'),
    );

    expect(fn () => (new LambdaSriRobotGateway($lambda, 'sri-txt-robot'))
        ->dispatch($request))
        ->toThrow(SriGatewayException::class, 'credenciales selladas');
});
