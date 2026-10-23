<?php

declare(strict_types=1);

use App\Domain\Tenancy\Exceptions\TenantContextNotSet;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\TenantAwareTestJob;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    TenantAwareTestJob::$handled = [];
});

afterEach(function (): void {
    app(TenantContext::class)->end();
});

it('ejecuta el job con el tenant activo', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->run($tenant, fn () => (new TenantAwareTestJob('demo'))->handle());

    expect(TenantAwareTestJob::$handled)->toBe([
        ['tenant_id' => $tenant->id, 'marker' => 'demo'],
    ]);
});

it('falla de forma ruidosa si el job se ejecuta sin tenant', function (): void {
    expect(fn () => (new TenantAwareTestJob('sin-tenant'))->handle())
        ->toThrow(TenantContextNotSet::class);

    expect(TenantAwareTestJob::$handled)->toBe([]);
});
