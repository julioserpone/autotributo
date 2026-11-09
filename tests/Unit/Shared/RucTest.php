<?php

declare(strict_types=1);

use App\Domain\Shared\Enums\RucType;
use App\Domain\Shared\Values\Ruc;

it('acepta RUC válidos y detecta el tipo de contribuyente', function (string $value, RucType $type): void {
    $ruc = Ruc::tryFrom($value);

    expect($ruc)->not->toBeNull()
        ->and($ruc?->value)->toBe($value)
        ->and($ruc?->type)->toBe($type)
        ->and((string) $ruc)->toBe($value)
        ->and($ruc?->equals(Ruc::from($value)))->toBeTrue();
})->with([
    'persona natural' => ['0602910945001', RucType::Natural],
    'persona natural otro establecimiento' => ['0602910945002', RucType::Natural],
    'persona jurídica' => ['0990000018001', RucType::Private],
    'sector público' => ['1760001550001', RucType::Public],
    'secuencial extendido sin dígito verificador' => ['0991000000001', RucType::Private],
]);

it('rechaza RUC inválidos', function (string $value): void {
    expect(Ruc::tryFrom($value))->toBeNull();
})->with([
    'vacío' => '',
    'con letras' => '06029109450A1',
    'corto' => '060291094500',
    'largo' => '06029109450012',
    'provincia inexistente' => '2526687856001',
    'tercer dígito no asignado' => '0186687856001',
    'dígito verificador natural' => '0926687858001',
    'dígito verificador jurídica' => '0990000019001',
    'dígito verificador pública' => '1760001520001',
    'establecimiento natural cero' => '0602910945000',
    'establecimiento público cero' => '1760001550000',
]);

it('lanza una excepción al construir un RUC inválido con from', function (): void {
    Ruc::from('0926687858001');
})->throws(InvalidArgumentException::class, 'RUC inválido: 0926687858001');
