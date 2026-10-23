<?php

declare(strict_types=1);

use App\Domain\Tenancy\Exceptions\TenantContextNotSet;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\Support\TenantScopedRecord;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Schema::dropIfExists('tenant_scoped_records');

    Schema::create('tenant_scoped_records', function (Blueprint $table): void {
        $table->id();
        $table->foreignId('tenant_id')->constrained('tenants')->cascadeOnDelete();
        $table->string('name');
    });
});

afterEach(function (): void {
    app(TenantContext::class)->end();
    Schema::dropIfExists('tenant_scoped_records');
});

it('rellena tenant_id y filtra las consultas por el tenant activo', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->run($tenantA, fn () => TenantScopedRecord::create(['name' => 'A']));
    $context->run($tenantB, fn () => TenantScopedRecord::create(['name' => 'B']));

    $namesA = $context->run(
        $tenantA,
        fn () => TenantScopedRecord::query()->pluck('name')->all(),
    );
    $namesB = $context->run(
        $tenantB,
        fn () => TenantScopedRecord::query()->pluck('name')->all(),
    );

    expect($namesA)->toBe(['A'])
        ->and($namesB)->toBe(['B']);
});

it('permite indicar tenant_id explícito para seeders y pruebas', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $context->run(
        $tenantA,
        fn () => TenantScopedRecord::create([
            'tenant_id' => $tenantB->id,
            'name' => 'De B',
        ]),
    );

    expect($context->run(
        $tenantB,
        fn () => TenantScopedRecord::query()->pluck('name')->all(),
    ))->toBe(['De B'])
        ->and($context->run(
            $tenantA,
            fn () => TenantScopedRecord::query()->pluck('name')->all(),
        ))->toBe([]);
});

it('expone la relación con el tenant usando eager loading', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);

    $record = $context->run($tenant, fn () => TenantScopedRecord::create(['name' => 'A']));
    $record = $context->run(
        $tenant,
        fn () => TenantScopedRecord::query()->with('tenant')->findOrFail($record->id),
    );

    expect($record->tenant?->is($tenant))->toBeTrue();
});

it('falla al consultar sin contexto en lugar de mezclar tenants', function (): void {
    expect(fn () => TenantScopedRecord::query()->get())
        ->toThrow(TenantContextNotSet::class);
});

it('falla al crear sin contexto', function (): void {
    expect(fn () => TenantScopedRecord::create(['name' => 'Sin tenant']))
        ->toThrow(TenantContextNotSet::class);
});
