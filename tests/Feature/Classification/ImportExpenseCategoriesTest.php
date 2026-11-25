<?php

declare(strict_types=1);

use App\Domain\Classification\Catalogs\CsvExpenseCategoryReader;
use App\Domain\Classification\Models\ExpenseCategory;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

it('lee el CSV del set base con padres y subcategorías', function (): void {
    $rows = (new CsvExpenseCategoryReader)->read(
        (string) file_get_contents(base_path('storage/catalogs/expense-categories-base.csv')),
    );

    $codes = array_column($rows, 'code');

    expect($rows)->toHaveCount(36)
        ->and($codes)->toContain('alimentacion', 'alimentacion_supermercado', 'comestibles')
        ->and(collect($rows)->firstWhere('code', 'alimentacion_supermercado')['parent_code'])->toBe('alimentacion');
});

it('importa el set base resolviendo los padres por código', function (): void {
    $this->artisan('categories:import', ['file' => 'storage/catalogs/expense-categories-base.csv'])
        ->expectsOutputToContain('36 creadas')
        ->assertExitCode(0);

    $parent = ExpenseCategory::query()->where('code', 'alimentacion')->firstOrFail();
    $child = ExpenseCategory::query()->where('code', 'alimentacion_supermercado')->firstOrFail();

    expect($child->parent_id)->toBe($parent->id);
});

it('es idempotente al reimportar el set base', function (): void {
    $this->artisan('categories:import', ['file' => 'storage/catalogs/expense-categories-base.csv'])->assertExitCode(0);
    $this->artisan('categories:import', ['file' => 'storage/catalogs/expense-categories-base.csv'])
        ->expectsOutputToContain('0 creadas')
        ->assertExitCode(0);

    expect(ExpenseCategory::query()->count())->toBe(36);
});

it('en dry-run no escribe categorías', function (): void {
    $this->artisan('categories:import', ['file' => 'storage/catalogs/expense-categories-base.csv', '--dry-run' => true])
        ->assertExitCode(0);

    expect(ExpenseCategory::query()->count())->toBe(0);
});

it('rechaza un CSV sin las columnas obligatorias', function (): void {
    $path = tempnam(sys_get_temp_dir(), 'cats_');
    file_put_contents((string) $path, "code,name\nx,Y\n");

    $this->artisan('categories:import', ['file' => (string) $path])->assertExitCode(1);

    expect(ExpenseCategory::query()->count())->toBe(0);

    @unlink((string) $path);
});
