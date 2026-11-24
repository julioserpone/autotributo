<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Enums\FiscalRiskStatus;
use App\Domain\Fiscal\Models\FiscalFinding;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Sri\AccessKeyFactory;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'reconcile']);
    $this->context = app(TenantContext::class);
    $this->context->initialize($this->tenant);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '0990000018001',
    ]);
});

afterEach(function (): void {
    $this->context->end();
});

function reconciliationDocument(array $overrides = []): FiscalDocument
{
    return FiscalDocument::factory()->create(array_merge([
        'tenant_id' => test()->tenant->id,
        'company_id' => test()->company->id,
        'issuer_ruc' => '1758871873001',
        'totals_mismatch' => false,
    ], $overrides));
}

it('elimina los hallazgos que apuntan a comprobantes inexistentes con --purge-synthetic', function (): void {
    $orphan = FiscalFinding::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'code' => 'TOTALS_MISMATCH',
        'status' => FiscalRiskStatus::Open->value,
        'source_id' => 9_999,
    ]);

    $this->artisan('fiscal:reconcile-findings', ['--purge-synthetic' => true])
        ->assertExitCode(0);

    expect(FiscalFinding::query()->whereKey($orphan->id)->exists())->toBeFalse();
});

it('no borra huérfanos sin el flag y solo los advierte', function (): void {
    $orphan = FiscalFinding::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'code' => 'TOTALS_MISMATCH',
        'status' => FiscalRiskStatus::Open->value,
        'source_id' => 9_998,
    ]);

    $this->artisan('fiscal:reconcile-findings')
        ->expectsOutputToContain('comprobantes inexistentes')
        ->assertExitCode(0);

    expect(FiscalFinding::query()->whereKey($orphan->id)->exists())->toBeTrue();
});

it('cierra los falsos hallazgos de totales cuando el comprobante sí cuadra', function (): void {
    // Caso real: base 2,62 neta, descuento global 0,89 y total 2,62.
    $document = reconciliationDocument([
        'totals_mismatch' => true,
        'subtotal_cents' => 262,
        'discount_cents' => 89,
        'tax_cents' => 0,
        'total_cents' => 262,
    ]);

    $finding = FiscalFinding::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'code' => 'TOTALS_MISMATCH',
        'status' => FiscalRiskStatus::Open->value,
        'source_id' => $document->id,
        'metadata' => ['access_key' => $document->access_key],
    ]);

    $this->artisan('fiscal:reconcile-findings')->assertExitCode(0);

    $finding->refresh();

    expect($finding->status)->toBe(FiscalRiskStatus::Resolved)
        ->and($finding->metadata['note'])->toContain('cuadra')
        ->and($document->fresh()?->totals_mismatch)->toBeFalse();
});

it('mantiene abierto un desvío real de totales', function (): void {
    $document = reconciliationDocument([
        'totals_mismatch' => true,
        'subtotal_cents' => 10_000,
        'discount_cents' => 0,
        'tax_cents' => 1_500,
        'total_cents' => 12_000,
    ]);

    FiscalFinding::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'code' => 'TOTALS_MISMATCH',
        'status' => FiscalRiskStatus::Open->value,
        'source_id' => $document->id,
    ]);

    $this->artisan('fiscal:reconcile-findings')->assertExitCode(0);

    expect(FiscalFinding::query()->where('code', 'TOTALS_MISMATCH')->where('status', FiscalRiskStatus::Open->value)->count())->toBe(1);
});

it('en --dry-run no escribe nada', function (): void {
    $document = reconciliationDocument([
        'totals_mismatch' => true,
        'subtotal_cents' => 262,
        'discount_cents' => 89,
        'tax_cents' => 0,
        'total_cents' => 262,
    ]);

    $finding = FiscalFinding::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'code' => 'TOTALS_MISMATCH',
        'status' => FiscalRiskStatus::Open->value,
        'source_id' => $document->id,
    ]);

    $this->artisan('fiscal:reconcile-findings', ['--dry-run' => true])->assertExitCode(0);

    expect($finding->fresh()?->status)->toBe(FiscalRiskStatus::Open)
        ->and($document->fresh()?->totals_mismatch)->toBeTrue();
});

it('limita el saneamiento a la empresa indicada', function (): void {
    $otherCompany = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '0990000018002',
    ]);

    $otherDocument = FiscalDocument::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $otherCompany->id,
        'issuer_ruc' => '1758871873001',
        'totals_mismatch' => true,
        'subtotal_cents' => 10_000,
        'tax_cents' => 1_500,
        'total_cents' => 12_000,
    ]);

    $this->artisan('fiscal:reconcile-findings', ['--company' => $this->company->public_id])->assertExitCode(0);

    expect(FiscalFinding::query()->where('company_id', $otherCompany->id)->count())->toBe(0)
        ->and($otherDocument->exists)->toBeTrue();
});

it('reinterpreta el XML guardado y deja el comprobante cuadrado', function (): void {
    Storage::fake('sri');

    $key = AccessKeyFactory::make();
    $xml = str_replace('{{CLAVE}}', $key, (string) file_get_contents(__DIR__.'/../../Fixtures/xml/fiscal_document/factura.xml'));
    $path = '2/1758871873001/2026-09/'.$key.'.xml';

    Storage::disk('sri')->put($path, $xml);

    $document = reconciliationDocument([
        'access_key' => $key,
        'xml_path' => $path,
        'totals_mismatch' => true,
        'subtotal_cents' => 1_000,
        'tax_cents' => 0,
        'total_cents' => 1_000,
    ]);

    $this->artisan('fiscal:reparse-documents', ['access_keys' => [$key]])->assertExitCode(0);

    $document->refresh();

    expect($document->subtotal_cents)->toBe(9_000)
        ->and($document->tax_cents)->toBe(1_350)
        ->and($document->total_cents)->toBe(10_350)
        ->and($document->totals_mismatch)->toBeFalse();
});

it('avisa cuando el XML ya no está y no modifica el comprobante', function (): void {
    Storage::fake('sri');

    $document = reconciliationDocument([
        'xml_path' => '2/1758871873001/2026-10/inexistente.xml',
        'totals_mismatch' => true,
        'total_cents' => 1_000,
    ]);

    $this->artisan('fiscal:reparse-documents', ['access_keys' => [$document->access_key]])
        ->expectsOutputToContain('no está en el almacenamiento')
        ->assertExitCode(0);

    expect($document->fresh()?->total_cents)->toBe(1_000)
        ->and($document->fresh()?->totals_mismatch)->toBeTrue();
});

it('en --dry-run informa sin guardar', function (): void {
    Storage::fake('sri');

    $key = AccessKeyFactory::make();
    $xml = str_replace('{{CLAVE}}', $key, (string) file_get_contents(__DIR__.'/../../Fixtures/xml/fiscal_document/factura.xml'));
    Storage::disk('sri')->put('2/1758871873001/2026-09/'.$key.'.xml', $xml);

    $document = reconciliationDocument([
        'access_key' => $key,
        'xml_path' => '2/1758871873001/2026-09/'.$key.'.xml',
        'total_cents' => 1_000,
    ]);

    $this->artisan('fiscal:reparse-documents', ['access_keys' => [$key], '--dry-run' => true])->assertExitCode(0);

    expect($document->fresh()?->total_cents)->toBe(1_000);
});
