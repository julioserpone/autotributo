<?php

declare(strict_types=1);

use App\Domain\Tenancy\Models\Domain;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Database\Seeders\DefaultTenantSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('genera el public_id y castea la configuración', function (): void {
    $tenant = Tenant::factory()->create([
        'settings' => ['branding' => ['color' => '#123456']],
    ]);

    expect($tenant->public_id)->toHaveLength(26)
        ->and($tenant->settings)->toMatchArray([
            'branding' => ['color' => '#123456'],
        ])
        ->and($tenant->getTenantKey())->toBe($tenant->id)
        ->and($tenant->getTenantKeyName())->toBe('id')
        ->and($tenant->isActive())->toBeTrue();
});

it('guarda y recupera claves internas en settings', function (): void {
    $tenant = Tenant::factory()->create();

    $tenant->setInternal('tenancy_db_name', 'autotributo_demo')->save();
    $tenant->refresh();

    expect($tenant->getInternal('tenancy_db_name'))->toBe('autotributo_demo')
        ->and($tenant->getInternal('missing'))->toBeNull();
});

it('relaciona dominios con el tenant', function (): void {
    $tenant = Tenant::factory()->create();

    $domain = Domain::query()->create([
        'tenant_id' => $tenant->id,
        'domain' => 'demo.autotributo.test',
        'is_primary' => true,
    ]);

    $domain->load('tenant');
    $tenant->load('domains');

    expect($domain->tenant)->toBeInstanceOf(Tenant::class)
        ->and($domain->tenant?->is($tenant))->toBeTrue()
        ->and($tenant->domains)->toHaveCount(1);
});

it('ejecuta un callback en el contexto del tenant y restaura', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $seen = $tenant->run(fn (): int => $context->id());

    expect($seen)->toBe($tenant->id)
        ->and($context->isInitialized())->toBeFalse();
});

it('crea el tenant por defecto de forma idempotente', function (): void {
    $this->seed(DefaultTenantSeeder::class);
    $this->seed(DefaultTenantSeeder::class);

    expect(Tenant::query()->where('slug', config('tenancy.default_slug'))->count())->toBe(1);
});
