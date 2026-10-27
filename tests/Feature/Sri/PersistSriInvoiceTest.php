<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Sri\Actions\PersistSriInvoice;
use App\Domain\Sri\Contracts\SriInvoiceParser;
use App\Domain\Sri\Data\SriImportResult;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriImportOutcome;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Sri\AccessKeyFactory;

/**
 * Carga el XML de un fixture con la clave de acceso del caso.
 */
function invoiceXml(string $path, string $key): string
{
    $xml = file_get_contents(__DIR__.'/../../Fixtures/xml/'.$path.'/'.basename($path).'.xml');

    if ($xml === false) {
        throw new RuntimeException("No se pudo leer el fixture {$path}.");
    }

    return str_replace('{{CLAVE}}', $key, $xml);
}

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->context = app(TenantContext::class);
    $this->context->initialize($this->tenant);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '1758871873001',
    ]);

    $this->persist = app(PersistSriInvoice::class);
    $this->parser = app(SriInvoiceParser::class);
});

afterEach(function (): void {
    $this->context->end();
});

function persistFixture(object $test, string $fixture, ?string $xml = null): SriImportResult
{
    $key = AccessKeyFactory::make();
    $contents = $xml ?? str_replace('{{CLAVE}}', $key, (string) file_get_contents(
        __DIR__.'/../../Fixtures/xml/'.$fixture,
    ));

    return $test->persist->handle($test->company, $test->parser->parse($contents), $contents);
}

it('persiste un comprobante y sus hijos', function (): void {
    $result = persistFixture($this, 'invoice/factura.xml');

    expect($result->outcome)->toBe(SriImportOutcome::Created)
        ->and($result->invoice->exists)->toBeTrue()
        ->and($result->invoice->tenant_id)->toBe($this->tenant->id)
        ->and($result->invoice->document_type)->toBe(SriDocumentType::Invoice)
        ->and($result->invoice->direction)->toBe(Invoice::DIRECTION_RECEIVED)
        ->and($result->invoice->source)->toBe(Invoice::SOURCE_SRI_ROBOT)
        ->and($result->invoice->subtotal_cents)->toBe(10_000)
        ->and($result->invoice->tax_cents)->toBe(1_350)
        ->and($result->invoice->total_cents)->toBe(10_350)
        ->and($result->invoice->totals_mismatch)->toBeFalse()
        ->and($result->invoice->items)->toHaveCount(2)
        ->and($result->invoice->taxes)->toHaveCount(2)
        ->and($result->invoice->items->first()->normalized_description)->toBe('SERVICIOS DE CONSULTORIA CONTABLE')
        ->and($result->invoice->items->first()->classification_status)->toBe('pending');
});

it('persiste las retenciones de un comprobante 07', function (): void {
    $key = AccessKeyFactory::make(type: '07');
    $xml = str_replace('{{CLAVE}}', $key, (string) file_get_contents(
        __DIR__.'/../../Fixtures/xml/retention/comprobante-retencion.xml',
    ));

    $result = $this->persist->handle($this->company, $this->parser->parse($xml), $xml);

    expect($result->invoice->document_type)->toBe(SriDocumentType::Withholding)
        ->and($result->invoice->retentions)->toHaveCount(2)
        ->and($result->invoice->retentions->first()->tax_type)->toBe('IR')
        ->and($result->invoice->retentions->first()->retention_code)->toBe('303')
        ->and($result->invoice->retentions->first()->amount_cents)->toBe(200)
        ->and($result->invoice->total_cents)->toBe(2_900);
});

it('es idempotente: el mismo XML no duplica filas', function (): void {
    $key = AccessKeyFactory::make();
    $xml = str_replace('{{CLAVE}}', $key, (string) file_get_contents(
        __DIR__.'/../../Fixtures/xml/invoice/factura.xml',
    ));
    $data = $this->parser->parse($xml);

    $first = $this->persist->handle($this->company, $data, $xml);
    $second = $this->persist->handle($this->company, $data, $xml);

    expect($first->outcome)->toBe(SriImportOutcome::Created)
        ->and($second->outcome)->toBe(SriImportOutcome::Skipped)
        ->and($second->invoice->id)->toBe($first->invoice->id)
        ->and(Invoice::query()->where('access_key', $key)->count())->toBe(1)
        ->and(InvoiceItem::query()->where('invoice_id', $first->invoice->id)->count())->toBe(2);
});

it('actualiza el comprobante cuando el XML cambió', function (): void {
    $key = AccessKeyFactory::make();
    $original = str_replace('{{CLAVE}}', $key, (string) file_get_contents(
        __DIR__.'/../../Fixtures/xml/invoice/factura.xml',
    ));
    $corrected = str_replace('<importeTotal>103.50</importeTotal>', '<importeTotal>103.60</importeTotal>', $original);

    $first = $this->persist->handle($this->company, $this->parser->parse($original), $original);
    $second = $this->persist->handle($this->company, $this->parser->parse($corrected), $corrected);

    expect($second->outcome)->toBe(SriImportOutcome::Updated)
        ->and($second->invoice->id)->toBe($first->invoice->id)
        ->and($second->invoice->total_cents)->toBe(10_360)
        ->and(Invoice::query()->count())->toBe(1)
        ->and(InvoiceItem::query()->count())->toBe(2)
        ->and($second->invoice->first_seen_at->equalTo($first->invoice->first_seen_at))->toBeTrue();
});

it('marca los comprobantes con totales inconsistentes sin corregirlos', function (): void {
    $key = AccessKeyFactory::make();
    $xml = str_replace(
        '<importeTotal>103.50</importeTotal>',
        '<importeTotal>104.00</importeTotal>',
        str_replace('{{CLAVE}}', $key, (string) file_get_contents(__DIR__.'/../../Fixtures/xml/invoice/factura.xml')),
    );

    $result = $this->persist->handle($this->company, $this->parser->parse($xml), $xml);

    expect($result->invoice->totals_mismatch)->toBeTrue()
        ->and($result->invoice->total_cents)->toBe(10_400);
});

it('no colisiona la misma clave entre empresas del mismo tenant', function (): void {
    $other = Company::factory()->create(['tenant_id' => $this->tenant->id, 'ruc' => '0999999999001']);
    $key = AccessKeyFactory::make();
    $xml = str_replace('{{CLAVE}}', $key, (string) file_get_contents(__DIR__.'/../../Fixtures/xml/invoice/factura.xml'));
    $data = $this->parser->parse($xml);

    $first = $this->persist->handle($this->company, $data, $xml);
    $second = $this->persist->handle($other, $data, $xml);

    expect($second->outcome)->toBe(SriImportOutcome::Created)
        ->and($second->invoice->id)->not->toBe($first->invoice->id)
        ->and(Invoice::query()->count())->toBe(2);
});

it('guarda la ruta del XML por ambiente, ruc y período', function (): void {
    $result = persistFixture($this, 'invoice/factura.xml');

    expect($result->invoice->xml_path)->toBe(sprintf('2/1758871873001/2026-09/%s.xml', $result->invoice->access_key))
        ->and($result->invoice->xml_sha256)->toHaveLength(64);
});
