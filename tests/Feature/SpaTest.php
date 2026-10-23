<?php

declare(strict_types=1);

use Database\Seeders\DefaultTenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->seed(DefaultTenantSeeder::class);
});

it('sirve el shell de la SPA', function (): void {
    $this->withoutVite();

    $this->get('/')
        ->assertOk()
        ->assertSee('id="app"', false)
        ->assertSee('Autotributo');
});

it('mantiene disponible el health check de Laravel', function (): void {
    $this->get('/up')->assertOk();
});

it('no captura las rutas de la API con el fallback de la SPA', function (): void {
    $this->getJson('/api/v1/health')
        ->assertOk()
        ->assertJsonPath('status', 'ok');
});
