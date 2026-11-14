<?php

declare(strict_types=1);

use App\Domain\Classification\Actions\SyncExpenseCategories;
use App\Domain\Classification\Models\ExpenseCategory;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Database\Seeders\ExpenseCategorySeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;

uses(RefreshDatabase::class);

it('carga las categorías de gasto de forma idempotente', function (): void {
    $first = app(SyncExpenseCategories::class)->handle();

    expect($first->created())->toBe(9)
        ->and($first->updated())->toBe(0)
        ->and($first->unchanged())->toBe(0)
        ->and(ExpenseCategory::query()->count())->toBe(9);

    $second = app(SyncExpenseCategories::class)->handle();

    expect($second->created())->toBe(0)
        ->and($second->updated())->toBe(0)
        ->and($second->unchanged())->toBe(9)
        ->and($second->hasChanges())->toBeFalse()
        ->and(ExpenseCategory::query()->count())->toBe(9);
});

it('mantiene el árbol y las banderas de deducibilidad', function (): void {
    app(SyncExpenseCategories::class)->handle();

    $alimentacion = ExpenseCategory::query()->where('code', 'alimentacion')->firstOrFail();

    expect($alimentacion->parent_id)->toBeNull()
        ->and($alimentacion->is_personal_deductible)->toBeTrue()
        ->and($alimentacion->is_business_deductible)->toBeFalse()
        ->and(ExpenseCategory::query()->where('code', 'operativo')->firstOrFail()->is_business_deductible)->toBeTrue()
        ->and(ExpenseCategory::query()->where('code', 'no_deducible')->firstOrFail()->is_personal_deductible)->toBeFalse()
        ->and(ExpenseCategory::query()->currentlyValid()->count())->toBe(9);
});

it('carga las categorías con el seeder', function (): void {
    $this->seed(ExpenseCategorySeeder::class);
    $this->seed(ExpenseCategorySeeder::class);

    expect(ExpenseCategory::query()->count())->toBe(9);
});

it('incluye las categorías al provisionar un estudio', function (): void {
    Notification::fake();

    config([
        'tenancy.mode' => 'single',
        'tenancy.default_slug' => 'test',
    ]);

    $this->artisan('app:provision', [
        '--name' => 'Estudio con categorías',
        '--owner-email' => 'owner@example.com',
        '--no-invite' => true,
    ])->assertExitCode(0);

    $tenant = Tenant::query()->where('slug', 'test')->firstOrFail();
    app(TenantContext::class)->initialize($tenant);

    expect(ExpenseCategory::query()->count())->toBe(9);
});
