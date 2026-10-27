<?php

declare(strict_types=1);

use App\Domain\Sri\Data\SriRobotObjectPath;
use App\Domain\Sri\Enums\SriDocumentType;

it('construye el layout real que publica sri-txt-robot', function (): void {
    $path = new SriRobotObjectPath('produccion', '1758871873001', '2026-08');

    expect($path->directory())->toBe('produccion/1758871873001/2026-08')
        ->and($path->prefix())->toBe('produccion/1758871873001/2026-08/')
        ->and($path->textReport(SriDocumentType::Invoice))
        ->toBe('produccion/1758871873001/2026-08/facturas-recibidas-2026-08.txt')
        ->and($path->accessKeys(SriDocumentType::Invoice))
        ->toBe('produccion/1758871873001/2026-08/facturas-recibidas-2026-08-claves.json')
        ->and($path->manifest())
        ->toBe('produccion/1758871873001/2026-08/manifest.json');
});

it('usa los slugs exactos de todos los tipos del robot', function (): void {
    $path = new SriRobotObjectPath('test', '1758871873001', '2026-08');

    expect(SriDocumentType::codes())->toBe(['01', '03', '04', '05', '06', '07'])
        ->and($path->textReport(SriDocumentType::Invoice))->toContain('/facturas-recibidas-')
        ->and($path->textReport(SriDocumentType::PurchaseSettlement))->toContain('/liquidaciones-compra-recibidas-')
        ->and($path->textReport(SriDocumentType::CreditNote))->toContain('/notas-credito-recibidas-')
        ->and($path->textReport(SriDocumentType::DebitNote))->toContain('/notas-debito-recibidas-')
        ->and($path->textReport(SriDocumentType::RemissionGuide))->toContain('/guias-remision-recibidas-')
        ->and($path->textReport(SriDocumentType::Withholding))->toContain('/retenciones-recibidas-');
});

it('normaliza el ambiente y conserva períodos diarios', function (): void {
    $path = new SriRobotObjectPath(' PRODUCCION ', '0999999999', '2026-08-15');

    expect($path->environment)->toBe('produccion')
        ->and($path->manifest())->toBe('produccion/0999999999/2026-08-15/manifest.json');
});

it('rechaza componentes inválidos de la ruta', function (string $environment, string $ruc, string $period): void {
    expect(fn () => new SriRobotObjectPath($environment, $ruc, $period))
        ->toThrow(InvalidArgumentException::class);
})->with([
    ['produccion/', '1758871873001', '2026-08'],
    ['produccion', '123', '2026-08'],
    ['produccion', '1758871873001', 'agosto'],
]);

it('expone etiquetas legibles sin acoplar el dominio al portal', function (): void {
    expect(SriDocumentType::Invoice->label())->toBe('Factura')
        ->and(SriDocumentType::Withholding->label())->toBe('Comprobante de retención')
        ->and(SriDocumentType::Invoice->slug())->toBe('facturas');
});
