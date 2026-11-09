<?php

declare(strict_types=1);

use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
});

it('provisiona tenant, roles y owner de forma idempotente', function (): void {
    $options = [
        '--name' => 'Estudio Ana',
        '--slug' => 'test',
        '--owner-email' => 'ana@example.com',
        '--owner-name' => 'Ana',
    ];

    $this->artisan('app:provision', $options)
        ->expectsOutputToContain('Estudio provisionado.')
        ->assertExitCode(0);

    $tenant = Tenant::query()->where('slug', 'test')->firstOrFail();
    $owner = User::query()->where('email', 'ana@example.com')->firstOrFail();

    app(TenantContext::class)->initialize($tenant);

    expect($tenant->name)->toBe('Estudio Ana')
        ->and($tenant->status)->toBe(Tenant::STATUS_ACTIVE)
        ->and($owner->hasRole('owner'))->toBeTrue()
        ->and(Membership::query()
            ->where('tenant_id', $tenant->id)
            ->where('user_id', $owner->id)
            ->where('role', 'owner')
            ->exists())->toBeTrue()
        ->and(Role::query()->where('tenant_id', $tenant->id)->count())->toBe(4);

    $this->artisan('app:provision', $options)
        ->expectsOutputToContain('Sin cambios.')
        ->assertExitCode(0);

    expect(Tenant::query()->count())->toBe(1)
        ->and(User::query()->count())->toBe(1)
        ->and(Membership::query()->count())->toBe(1);
});

it('envía un enlace de contraseña solo al crear el owner', function (): void {
    $options = [
        '--name' => 'Estudio Ana',
        '--slug' => 'test',
        '--owner-email' => 'ana@example.com',
    ];

    $this->artisan('app:provision', $options)->assertExitCode(0);

    $owner = User::query()->where('email', 'ana@example.com')->firstOrFail();

    Notification::assertSentTo($owner, ResetPassword::class);

    $this->artisan('app:provision', $options)->assertExitCode(0);

    Notification::assertSentToTimes($owner, ResetPassword::class, 1);
});

it('omite el enlace con --no-invite', function (): void {
    $this->artisan('app:provision', [
        '--name' => 'Estudio Ana',
        '--slug' => 'test',
        '--owner-email' => 'ana@example.com',
        '--no-invite' => true,
    ])->assertExitCode(0);

    Notification::assertNothingSent();
});

it('usa el slug por defecto cuando se omite', function (): void {
    config([
        'tenancy.mode' => 'single',
        'tenancy.default_slug' => 'test',
    ]);

    $this->artisan('app:provision', [
        '--name' => 'Estudio por defecto',
        '--owner-email' => 'owner@example.com',
        '--no-invite' => true,
    ])->assertExitCode(0);

    expect(Tenant::query()->where('slug', 'test')->exists())->toBeTrue();
});

it('rechaza un slug distinto al default en modo single', function (): void {
    $this->artisan('app:provision', [
        '--name' => 'Estudio Ana',
        '--slug' => 'otro',
        '--owner-email' => 'ana@example.com',
    ])
        ->expectsOutputToContain('debe coincidir con TENANCY_DEFAULT_SLUG')
        ->assertExitCode(1);

    expect(Tenant::query()->count())->toBe(0);
});

it('rechaza un segundo owner distinto', function (): void {
    $this->artisan('app:provision', [
        '--name' => 'Estudio Ana',
        '--slug' => 'test',
        '--owner-email' => 'ana@example.com',
    ])->assertExitCode(0);

    $this->artisan('app:provision', [
        '--name' => 'Estudio Ana',
        '--slug' => 'test',
        '--owner-email' => 'otro@example.com',
    ])
        ->expectsOutputToContain('ya tiene un owner')
        ->assertExitCode(1);

    expect(User::query()->count())->toBe(1);
});
