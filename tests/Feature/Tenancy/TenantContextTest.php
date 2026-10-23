<?php

declare(strict_types=1);

use App\Domain\Tenancy\Exceptions\InvalidTenancyMode;
use App\Domain\Tenancy\Exceptions\TenantContextNotSet;
use App\Domain\Tenancy\Exceptions\TenantNotFound;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;

uses(RefreshDatabase::class);

afterEach(function (): void {
    app(TenantContext::class)->end();
});

it('falla de forma ruidosa cuando no hay contexto', function (): void {
    $context = app(TenantContext::class);

    expect($context->isInitialized())->toBeFalse()
        ->and($context->current())->toBeNull();

    expect(fn () => $context->tenant())
        ->toThrow(TenantContextNotSet::class);
});

it('inicializa el tenant por defecto por slug', function (): void {
    $tenant = Tenant::factory()->create([
        'slug' => config('tenancy.default_slug'),
    ]);

    $context = app(TenantContext::class);
    $initialized = $context->initializeDefault();

    expect($initialized->is($tenant))->toBeTrue()
        ->and($context->isInitialized())->toBeTrue()
        ->and($context->id())->toBe($tenant->id)
        ->and($context->tenant()->is($tenant))->toBeTrue();
});

it('falla si el tenant por defecto no existe', function (): void {
    config(['tenancy.default_slug' => 'missing-tenant']);

    expect(fn () => app(TenantContext::class)->initializeDefault())
        ->toThrow(TenantNotFound::class);
});

it('restaura el contexto anterior al ejecutar un callback', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $result = $context->run($tenantA, function () use ($context, $tenantA, $tenantB): string {
        expect($context->id())->toBe($tenantA->id);

        return $context->run($tenantB, fn (): string => 'inner-'.$context->id());
    });

    expect($result)->toBe('inner-'.$tenantB->id)
        ->and($context->isInitialized())->toBeFalse();
});

it('inicializa y cierra el contexto en una ruta web', function (): void {
    $tenant = Tenant::factory()->create([
        'slug' => config('tenancy.default_slug'),
    ]);

    Route::middleware(['tenant.context', 'tenant.active'])
        ->get('/api/v1/_test/tenant-context', fn () => response()->json([
            'tenant_id' => app(TenantContext::class)->id(),
        ]));

    $this->getJson('/api/v1/_test/tenant-context')
        ->assertOk()
        ->assertJsonPath('tenant_id', $tenant->id);

    expect(app(TenantContext::class)->isInitialized())->toBeFalse();
});

it('rechaza requests de un tenant suspendido', function (): void {
    Tenant::factory()->suspended()->create([
        'slug' => config('tenancy.default_slug'),
    ]);

    Route::middleware(['tenant.context', 'tenant.active'])
        ->get('/api/v1/_test/suspended', fn () => response()->json(['ok' => true]));

    $this->getJson('/api/v1/_test/suspended')->assertForbidden();
});

it('falla si el middleware activo se ejecuta sin contexto', function (): void {
    Route::middleware('tenant.active')
        ->get('/api/v1/_test/no-context', fn () => response()->json(['ok' => true]));

    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/api/v1/_test/no-context'))
        ->toThrow(TenantContextNotSet::class);
});

it('preserva un contexto ya inicializado al pasar por el middleware', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $context->initialize($tenant);

    Route::middleware(['tenant.context', 'tenant.active'])
        ->get('/api/v1/_test/preserve-context', fn () => response()->json([
            'tenant_id' => app(TenantContext::class)->id(),
        ]));

    $this->getJson('/api/v1/_test/preserve-context')
        ->assertOk()
        ->assertJsonPath('tenant_id', $tenant->id);

    expect($context->isInitialized())->toBeTrue()
        ->and($context->id())->toBe($tenant->id);
});

it('no inicializa tenancy en modo multi sin un resolver', function (): void {
    config(['tenancy.mode' => 'multi']);

    Route::middleware('tenant.context')
        ->get('/api/v1/_test/multi', fn () => response()->json([
            'initialized' => app(TenantContext::class)->isInitialized(),
        ]));

    $this->getJson('/api/v1/_test/multi')
        ->assertOk()
        ->assertJsonPath('initialized', false);
});

it('rechaza un modo de tenancy inválido', function (): void {
    config(['tenancy.mode' => 'invalid']);

    Route::middleware('tenant.context')
        ->get('/api/v1/_test/invalid-mode', fn () => response()->json(['ok' => true]));

    $this->withoutExceptionHandling();

    expect(fn () => $this->getJson('/api/v1/_test/invalid-mode'))
        ->toThrow(InvalidTenancyMode::class);
});
