<?php

declare(strict_types=1);

use App\Domain\Sri\Data\SriRobotCredentials;

it('construye el contenido que se sella para el robot', function (): void {
    $credentials = new SriRobotCredentials(
        ruc: '1758871873001',
        password: 'clave-real',
        additionalKey: 'adicional',
        issuedAt: 1_760_000_000,
    );

    expect($credentials->toSealingPayload())->toBe([
        'ruc' => '1758871873001',
        'password' => 'clave-real',
        'claveAdicional' => 'adicional',
        'iat' => 1_760_000_000,
    ]);
});

it('usa la hora actual cuando no se fija iat', function (): void {
    $before = time();
    $payload = (new SriRobotCredentials('0999999999', 'clave-real'))->toSealingPayload();
    $after = time();

    expect($payload['iat'])->toBeGreaterThanOrEqual($before)
        ->and($payload['iat'])->toBeLessThanOrEqual($after);
});

it('redacta la contraseña al inspeccionar el objeto', function (): void {
    $credentials = new SriRobotCredentials('1758871873001', 'clave-real', 'adicional');

    expect($credentials->__debugInfo())->toBe([
        'ruc' => '1758871873001',
        'password' => '***',
        'claveAdicional' => '***',
        'iat' => null,
    ]);
});

it('rechaza credenciales inválidas', function (string $ruc, string $password, ?int $iat): void {
    expect(fn () => new SriRobotCredentials($ruc, $password, issuedAt: $iat))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'ruc corto' => ['123', 'clave-real', null],
    'contraseña vacía' => ['1758871873001', '', null],
    'iat inválido' => ['1758871873001', 'clave-real', 0],
]);
