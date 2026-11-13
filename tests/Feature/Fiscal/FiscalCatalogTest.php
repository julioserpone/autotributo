<?php

declare(strict_types=1);

use App\Domain\Fiscal\Actions\SyncFiscalCatalogs;
use App\Domain\Fiscal\Models\FormLine;
use App\Domain\Fiscal\Models\PersonalExpenseCap;
use App\Domain\Fiscal\Models\RetentionRate;
use App\Domain\Fiscal\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Notification::fake();
});

it('carga los catálogos fiscales de forma idempotente', function (): void {
    $first = app(SyncFiscalCatalogs::class)->handle();

    expect($first->created())->toBe(50)
        ->and($first->updated())->toBe(0)
        ->and(TaxRate::query()->count())->toBe(6)
        ->and(RetentionRate::query()->count())->toBe(22)
        ->and(FormLine::query()->count())->toBe(15)
        ->and(PersonalExpenseCap::query()->count())->toBe(7);

    $second = app(SyncFiscalCatalogs::class)->handle();

    expect($second->created())->toBe(0)
        ->and($second->updated())->toBe(0)
        ->and($second->unchanged())->toBe(50)
        ->and(TaxRate::query()->count())->toBe(6)
        ->and(RetentionRate::query()->count())->toBe(22)
        ->and(FormLine::query()->count())->toBe(15)
        ->and(PersonalExpenseCap::query()->count())->toBe(7);
});

it('resuelve la tarifa de IVA según la fecha de emisión', function (): void {
    app(SyncFiscalCatalogs::class)->handle();

    $rate = fn (string $date, string $code): string => (string) TaxRate::query()
        ->validOn($date)
        ->where('sri_percentage_code', $code)
        ->firstOrFail()
        ->rate;

    expect($rate('2016-08-15', '14'))->toBe('14.000000')
        ->and($rate('2024-03-15', '12'))->toBe('12.000000')
        ->and($rate('2024-04-15', '15'))->toBe('15.000000')
        ->and($rate('2024-04-15', '5'))->toBe('5.000000')
        ->and($rate('2026-06-01', '15'))->toBe('15.000000');
});

it('calcula el tope de gastos personales por cargas', function (): void {
    app(SyncFiscalCatalogs::class)->handle();

    $cap = fn (int $dependents): PersonalExpenseCap => PersonalExpenseCap::query()
        ->where('fiscal_year', 2026)
        ->where('category_code', 'global')
        ->where('dependents', $dependents)
        ->firstOrFail();

    expect($cap(0)->cap_cents)->toBe(575_260)
        ->and($cap(3)->baskets)->toBe(14)
        ->and($cap(3)->cap_cents)->toBe(1_150_520)
        ->and($cap(5)->cap_cents)->toBe(1_643_600)
        ->and(PersonalExpenseCap::query()
            ->where('category_code', 'catastrophic_illness')
            ->firstOrFail()
            ->cap_cents)->toBe(8_218_000);
});

it('valida los catálogos sin incidencias', function (): void {
    app(SyncFiscalCatalogs::class)->handle();

    $this->artisan('catalogs:validate')
        ->expectsOutputToContain('Catálogos fiscales válidos')
        ->assertExitCode(0);
});

it('detecta solapamientos de vigencia', function (): void {
    app(SyncFiscalCatalogs::class)->handle();

    TaxRate::query()->create([
        'code' => 'IVA',
        'sri_percentage_code' => '15',
        'rate' => '16.000000',
        'label' => 'Tarifa inválida de prueba',
        'valid_from' => '2024-01-01',
        'valid_to' => '2025-01-01',
        'source' => 'test',
    ]);

    $this->artisan('catalogs:validate')
        ->expectsOutputToContain('solapamiento')
        ->assertExitCode(1);
});

it('detecta topes que no coinciden con las canastas', function (): void {
    app(SyncFiscalCatalogs::class)->handle();

    PersonalExpenseCap::query()->create([
        'fiscal_year' => 2027,
        'category_code' => 'global',
        'dependents' => 0,
        'cap_cents' => 1,
        'baskets' => 7,
        'basic_food_basket_cents' => 82_180,
        'valid_from' => '2027-01-01',
        'valid_to' => '2027-12-31',
        'source' => 'test',
    ]);

    $this->artisan('catalogs:validate')
        ->expectsOutputToContain('cap_cents')
        ->assertExitCode(1);
});

it('carga los catálogos al provisionar el estudio', function (): void {
    $options = [
        '--name' => 'Estudio Fiscal',
        '--slug' => 'test',
        '--owner-email' => 'fiscal@example.com',
        '--no-invite' => true,
    ];

    $this->artisan('app:provision', $options)
        ->expectsOutputToContain('Catálogos fiscales')
        ->assertExitCode(0);

    expect(RetentionRate::query()->count())->toBe(22);

    $this->artisan('app:provision', $options)
        ->expectsOutputToContain('Sin cambios.')
        ->assertExitCode(0);

    expect(RetentionRate::query()->count())->toBe(22);
});
