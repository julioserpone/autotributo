<?php

declare(strict_types=1);

use App\Domain\Sri\Data\SriRobotCredentials;
use App\Domain\Sri\Data\SriRobotRequest;

it('construye el contrato exacto del robot', function (): void {
    $request = new SriRobotRequest(
        ruc: '1758871873001',
        periods: ['2026-08', '2026-09', '2026-08'],
        documentTypes: ['01', '04', '01'],
        force: true,
    );

    expect($request->toPayload())->toBe([
        'ruc' => '1758871873001',
        'periodos' => ['2026-08', '2026-09'],
        'tipos' => ['01', '04'],
        'forzar' => true,
    ]);
});

it('permite omitir tipos para que el robot use todos los configurados', function (): void {
    $request = new SriRobotRequest('0999999999', ['2026-08']);

    expect($request->toPayload()['tipos'])->toBe([]);
});

it('rechaza períodos, RUC o tipos inválidos', function (string $field): void {
    $args = match ($field) {
        'ruc' => ['123', ['2026-08']],
        'period' => ['1758871873001', ['agosto']],
        'type' => ['1758871873001', ['2026-08'], ['02']],
        default => ['1758871873001', []],
    };

    expect(fn () => new SriRobotRequest(...$args))
        ->toThrow(InvalidArgumentException::class);
})->with(['ruc', 'period', 'type', 'empty-periods']);

it('incluye el sobre sellado en el payload', function (): void {
    $request = new SriRobotRequest(
        ruc: '1758871873001',
        periods: ['2026-08'],
        documentTypes: ['01'],
        credentials: new SriRobotCredentials('1758871873001', 'clave-real'),
    );

    expect($request->toPayload('sobre-sellado'))->toBe([
        'ruc' => '1758871873001',
        'periodos' => ['2026-08'],
        'tipos' => ['01'],
        'forzar' => false,
        'sealed_credentials' => 'sobre-sellado',
    ]);
});

it('no permite construir el payload con credenciales sin sellar', function (): void {
    $request = new SriRobotRequest(
        ruc: '1758871873001',
        periods: ['2026-08'],
        credentials: new SriRobotCredentials('1758871873001', 'clave-real'),
    );

    expect(fn () => $request->toPayload())->toThrow(InvalidArgumentException::class);
});

it('rechaza credenciales de otro RUC', function (): void {
    expect(fn () => new SriRobotRequest(
        ruc: '1758871873001',
        periods: ['2026-08'],
        credentials: new SriRobotCredentials('0999999999001', 'clave-real'),
    ))->toThrow(InvalidArgumentException::class);
});
