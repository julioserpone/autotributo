<?php

declare(strict_types=1);

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    app(TenantContext::class)->end();
});

it('aísla los archivos por tenant en el disco local', function (): void {
    $tenant = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $path = 'tenancy-proof-'.Str::uuid().'.txt';

    $context->run($tenant, fn () => Storage::disk('local')->put($path, 'hola'));

    $tenantFile = storage_path("app/private/tenants/{$tenant->id}/{$path}");

    expect(file_exists($tenantFile))->toBeTrue()
        ->and(Storage::disk('local')->exists($path))->toBeFalse();

    File::deleteDirectory(storage_path("app/private/tenants/{$tenant->id}"));
});

it('aísla la caché por tenant y no filtra valores al contexto central', function (): void {
    $tenantA = Tenant::factory()->create();
    $tenantB = Tenant::factory()->create();
    $context = app(TenantContext::class);
    $key = 'tenancy-proof-'.Str::uuid();

    $context->run($tenantA, function () use ($key): void {
        Cache::put($key, 'valor-A', 60);

        expect(Cache::get($key))->toBe('valor-A');
    });

    expect(Cache::get($key))->toBeNull()
        ->and($context->run($tenantB, fn () => Cache::get($key)))->toBeNull();
});
