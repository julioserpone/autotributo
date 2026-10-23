<?php

declare(strict_types=1);

use App\Domain\Sri\Contracts\SriRobotObjectStore;
use App\Domain\Sri\Exceptions\SriRobotObjectNotFound;
use App\Integrations\Sri\FilesystemSriRobotObjectStore;
use Illuminate\Support\Facades\Storage;

it('lee y verifica objetos del disco sri', function (): void {
    Storage::fake('sri');
    $key = 'produccion/1758871873001/2026-08/facturas-recibidas-2026-08.txt';
    Storage::disk('sri')->put($key, 'contenido del reporte');

    $store = app(SriRobotObjectStore::class);

    expect($store)->toBeInstanceOf(FilesystemSriRobotObjectStore::class)
        ->and($store->exists($key))->toBeTrue()
        ->and($store->read($key))->toBe('contenido del reporte')
        ->and($store->sha256($key))->toBe(hash('sha256', 'contenido del reporte'));
});

it('falla de forma explícita cuando el robot aún no publicó el objeto', function (): void {
    Storage::fake('sri');

    $store = app(SriRobotObjectStore::class);

    expect($store->exists('produccion/1758871873001/2026-08/manifest.json'))->toBeFalse()
        ->and(fn () => $store->read('produccion/1758871873001/2026-08/manifest.json'))
        ->toThrow(SriRobotObjectNotFound::class);
});

it('falla al calcular el checksum de un objeto ausente', function (): void {
    Storage::fake('sri');

    expect(fn () => app(SriRobotObjectStore::class)->sha256('produccion/ausente.json'))
        ->toThrow(SriRobotObjectNotFound::class);
});
