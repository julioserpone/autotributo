<?php

declare(strict_types=1);

use App\Domain\Fiscal\Actions\ImportFiscalCatalog;
use App\Domain\Fiscal\Models\PersonalExpenseCap;
use App\Domain\Fiscal\Models\RetentionRate;
use App\Domain\Fiscal\Models\TaxRate;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

function writeCatalogCsv(string $contents): string
{
    $path = tempnam(sys_get_temp_dir(), 'catalog_');

    if ($path === false) {
        throw new RuntimeException('No se pudo crear el CSV temporal.');
    }

    file_put_contents($path, $contents);

    return $path;
}

it('importa tarifas de IVA desde un CSV del SRI con cabeceras en español', function (): void {
    $csv = "Codigo;Código porcentaje;Tarifa;Descripción;Vigencia desde;Vigencia hasta\n"
        ."IVA;0;0;IVA 0%;2024-04-01;\n"
        ."IVA;4;15;IVA 15%;2024-04-01;\n"
        ."IVA;4;12;IVA 12%;2016-06-01;2024-03-31\n";

    $result = app(ImportFiscalCatalog::class)->handle('tax_rates', $csv);

    expect($result['created'])->toBe(3)
        ->and(TaxRate::query()->count())->toBe(3);

    $current = TaxRate::query()->currentlyValid()->where('sri_percentage_code', '4')->firstOrFail();

    expect((float) $current->rate)->toBe(15.0);

    $historical = TaxRate::query()->where('valid_to', '2024-03-31')->firstOrFail();

    expect((float) $historical->rate)->toBe(12.0);
});

it('es idempotente al importar el mismo CSV dos veces', function (): void {
    $csv = "code,sri_percentage_code,rate,label,valid_from\nIVA,4,15,IVA 15%,2024-04-01\n";

    $first = app(ImportFiscalCatalog::class)->handle('tax_rates', $csv);
    $second = app(ImportFiscalCatalog::class)->handle('tax_rates', $csv);

    expect($first['created'])->toBe(1)
        ->and($second['created'])->toBe(0)
        ->and($second['updated'])->toBe(0)
        ->and($second['unchanged'])->toBe(1)
        ->and(TaxRate::query()->count())->toBe(1);
});

it('actualiza la etiqueta cuando cambia el mismo código y vigencia', function (): void {
    $first = "code,sri_percentage_code,rate,label,valid_from\nIVA,4,15,IVA 15%%,2024-04-01\n";
    $second = "code,sri_percentage_code,rate,label,valid_from\nIVA,4,15,IVA general 15%,2024-04-01\n";

    app(ImportFiscalCatalog::class)->handle('tax_rates', $first);
    $result = app(ImportFiscalCatalog::class)->handle('tax_rates', $second);

    expect($result['updated'])->toBe(1)
        ->and(TaxRate::query()->where('code', 'IVA')->value('label'))->toBe('IVA general 15%');
});

it('importa retenciones y calcula el tope de gastos personales desde las canastas', function (): void {
    app(ImportFiscalCatalog::class)->handle(
        'retention_rates',
        "tax_type,code,rate,label,valid_from\nIR,303,2,Servicios profesionales,2024-01-01\n",
    );

    app(ImportFiscalCatalog::class)->handle(
        'personal_expense_caps',
        "fiscal_year,category_code,dependents,baskets,basic_food_basket_cents,valid_from\n"
        ."2026,global,0,7,82180,2026-01-01\n",
    );

    expect((float) RetentionRate::query()->firstOrFail()->rate)->toBe(2.0)
        ->and(PersonalExpenseCap::query()->firstOrFail()->cap_cents)->toBe(7 * 82_180);
});

it('rechaza una columna que no pertenece al catálogo', function (): void {
    $csv = "code,rate,columna_inventada,valid_from\nIVA,15,x,2024-04-01\n";

    expect(fn () => app(ImportFiscalCatalog::class)->handle('tax_rates', $csv))
        ->toThrow(RuntimeException::class, 'no corresponde a este catálogo');

    expect(TaxRate::query()->count())->toBe(0);
});

it('rechaza una fila sin la columna obligatoria', function (): void {
    $csv = "code,label,valid_from\nIVA,IVA 15%,2024-04-01\n";

    expect(fn () => app(ImportFiscalCatalog::class)->handle('tax_rates', $csv))
        ->toThrow(RuntimeException::class, 'columna obligatoria');

    expect(TaxRate::query()->count())->toBe(0);
});

it('rechaza una vigencia que termina antes de empezar', function (): void {
    $csv = "code,sri_percentage_code,rate,label,valid_from,valid_to\nIVA,4,15,IVA 15%,2024-04-01,2024-03-01\n";

    expect(fn () => app(ImportFiscalCatalog::class)->handle('tax_rates', $csv))
        ->toThrow(RuntimeException::class, 'termina antes de empezar');
});

it('rechaza una tarifa negativa', function (): void {
    $csv = "code,sri_percentage_code,rate,label,valid_from\nIVA,4,-5,IVA negativo,2024-04-01\n";

    expect(fn () => app(ImportFiscalCatalog::class)->handle('tax_rates', $csv))
        ->toThrow(RuntimeException::class, 'tarifa negativa');
});

it('rechaza un catálogo desconocido', function (): void {
    expect(fn () => app(ImportFiscalCatalog::class)->handle('iva', 'code,rate\nIVA,15\n'))
        ->toThrow(RuntimeException::class, 'Catálogo desconocido');
});

it('en modo dry-run cuenta pero no escribe', function (): void {
    $csv = "code,sri_percentage_code,rate,label,valid_from\nIVA,4,15,IVA 15%,2024-04-01\n";

    $result = app(ImportFiscalCatalog::class)->handle('tax_rates', $csv, dryRun: true);

    expect($result['created'])->toBe(1)
        ->and(TaxRate::query()->count())->toBe(0);
});

it('el comando importa el CSV y deja el catálogo disponible por API', function (): void {
    $path = writeCatalogCsv("code,sri_percentage_code,rate,label,valid_from\nIVA,4,15,IVA 15%,2024-04-01\n");

    $this->artisan('catalogs:import', ['catalog' => 'tax_rates', 'file' => $path])
        ->expectsOutputToContain('1 creadas')
        ->assertExitCode(0);

    expect(TaxRate::query()->count())->toBe(1);

    @unlink($path);
});

it('el comando informa un CSV inválido sin escribir', function (): void {
    $path = writeCatalogCsv("code,rate\nIVA,15\n");

    $this->artisan('catalogs:import', ['catalog' => 'tax_rates', 'file' => $path])
        ->assertExitCode(1);

    expect(TaxRate::query()->count())->toBe(0);

    @unlink($path);
});
