<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\FiscalDocuments\Models\FiscalDocumentItem;
use App\Domain\Sri\Actions\PersistSriFiscalDocument;
use App\Domain\Sri\Contracts\SriFiscalDocumentParser;
use App\Domain\Sri\Data\SriImportResult;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriImportOutcome;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\Sri\AccessKeyFactory;

/**
 * Carga el XML de un fixture con la clave de acceso del caso.
 */
function fiscal_documentXml(string $path, string $key): string
{
    $xml = file_get_contents(__DIR__.'/../../Fixtures/xml/'.$path);

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

    $this->persist = app(PersistSriFiscalDocument::class);
    $this->parser = app(SriFiscalDocumentParser::class);
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
    $result = persistFixture($this, 'fiscal_document/factura.xml');

    expect($result->outcome)->toBe(SriImportOutcome::Created)
        ->and($result->fiscal_document->exists)->toBeTrue()
        ->and($result->fiscal_document->tenant_id)->toBe($this->tenant->id)
        ->and($result->fiscal_document->document_type)->toBe(SriDocumentType::FiscalDocument)
        ->and($result->fiscal_document->direction)->toBe(FiscalDocument::DIRECTION_ISSUED)
        ->and($result->fiscal_document->source)->toBe(FiscalDocument::SOURCE_SRI_ROBOT)
        ->and($result->fiscal_document->subtotal_cents)->toBe(9_000)
        ->and($result->fiscal_document->tax_cents)->toBe(1_350)
        ->and($result->fiscal_document->total_cents)->toBe(10_350)
        ->and($result->fiscal_document->totals_mismatch)->toBeFalse()
        ->and($result->fiscal_document->items)->toHaveCount(2)
        ->and($result->fiscal_document->taxes)->toHaveCount(2)
        ->and($result->fiscal_document->items->first()->normalized_description)->toBe('SERVICIOS DE CONSULTORIA CONTABLE')
        ->and($result->fiscal_document->items->first()->classification_status)->toBe('pending');
});

it('persiste las retenciones de un comprobante 07', function (): void {
    $key = AccessKeyFactory::make(type: '07');
    $xml = str_replace('{{CLAVE}}', $key, (string) file_get_contents(
        __DIR__.'/../../Fixtures/xml/retention/comprobante-retencion.xml',
    ));

    $result = $this->persist->handle($this->company, $this->parser->parse($xml), $xml);

    expect($result->fiscal_document->document_type)->toBe(SriDocumentType::Withholding)
        ->and($result->fiscal_document->retentions)->toHaveCount(2)
        ->and($result->fiscal_document->retentions->first()->tax_type)->toBe('IR')
        ->and($result->fiscal_document->retentions->first()->retention_code)->toBe('303')
        ->and($result->fiscal_document->retentions->first()->amount_cents)->toBe(200)
        ->and($result->fiscal_document->total_cents)->toBe(2_900);
});

it('es idempotente: el mismo XML no duplica filas', function (): void {
    $key = AccessKeyFactory::make();
    $xml = str_replace('{{CLAVE}}', $key, (string) file_get_contents(
        __DIR__.'/../../Fixtures/xml/fiscal_document/factura.xml',
    ));
    $data = $this->parser->parse($xml);

    $first = $this->persist->handle($this->company, $data, $xml);
    $second = $this->persist->handle($this->company, $data, $xml);

    expect($first->outcome)->toBe(SriImportOutcome::Created)
        ->and($second->outcome)->toBe(SriImportOutcome::Skipped)
        ->and($second->fiscal_document->id)->toBe($first->fiscal_document->id)
        ->and(FiscalDocument::query()->where('access_key', $key)->count())->toBe(1)
        ->and(FiscalDocumentItem::query()->where('fiscal_document_id', $first->fiscal_document->id)->count())->toBe(2);
});

it('re-importa el comprobante cuando se fuerza aunque el XML no cambió', function (): void {
    $key = AccessKeyFactory::make();
    $xml = str_replace('{{CLAVE}}', $key, (string) file_get_contents(
        __DIR__.'/../../Fixtures/xml/fiscal_document/factura.xml',
    ));
    $data = $this->parser->parse($xml);

    $first = $this->persist->handle($this->company, $data, $xml);
    $second = $this->persist->handle(
        $this->company,
        $data,
        $xml,
        null,
        FiscalDocument::SOURCE_SRI_ROBOT,
        true,
    );

    expect($first->outcome)->toBe(SriImportOutcome::Created)
        ->and($second->outcome)->toBe(SriImportOutcome::Updated)
        ->and(FiscalDocument::query()->where('access_key', $key)->count())->toBe(1);
});
it('actualiza el comprobante cuando el XML cambió', function (): void {
    $key = AccessKeyFactory::make();
    $original = str_replace('{{CLAVE}}', $key, (string) file_get_contents(
        __DIR__.'/../../Fixtures/xml/fiscal_document/factura.xml',
    ));
    $corrected = str_replace('<importeTotal>103.50</importeTotal>', '<importeTotal>103.60</importeTotal>', $original);

    $first = $this->persist->handle($this->company, $this->parser->parse($original), $original);
    $second = $this->persist->handle($this->company, $this->parser->parse($corrected), $corrected);

    expect($second->outcome)->toBe(SriImportOutcome::Updated)
        ->and($second->fiscal_document->id)->toBe($first->fiscal_document->id)
        ->and($second->fiscal_document->total_cents)->toBe(10_360)
        ->and(FiscalDocument::query()->count())->toBe(1)
        ->and(FiscalDocumentItem::query()->count())->toBe(2)
        ->and($second->fiscal_document->first_seen_at->equalTo($first->fiscal_document->first_seen_at))->toBeTrue();
});

it('re-importa el comprobante cuando el run fue forzado aunque el XML no cambió', function (): void {
    $key = AccessKeyFactory::make();
    $xml = str_replace('{{CLAVE}}', $key, (string) file_get_contents(
        __DIR__.'/../../Fixtures/xml/fiscal_document/factura.xml',
    ));
    $data = $this->parser->parse($xml);

    $run = SriSyncRun::query()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'status' => SriSyncRunStatus::Parsing,
        'date_from' => '2026-10-01',
        'date_to' => '2026-10-31',
        'periods' => ['2026-10'],
        'document_types' => ['01'],
        'idempotency_key' => 'forced-'.uniqid(),
        'force' => true,
        'totals' => ['found' => 1, 'imported' => 0, 'skipped' => 0, 'failed' => 0],
    ]);

    $first = $this->persist->handle($this->company, $data, $xml);
    $second = $this->persist->handle($this->company, $data, $xml, $run);

    expect($first->outcome)->toBe(SriImportOutcome::Created)
        ->and($second->outcome)->toBe(SriImportOutcome::Updated);
});
it('marca los comprobantes con totales inconsistentes sin corregirlos', function (): void {
    $key = AccessKeyFactory::make();
    $xml = str_replace(
        '<importeTotal>103.50</importeTotal>',
        '<importeTotal>104.00</importeTotal>',
        str_replace('{{CLAVE}}', $key, (string) file_get_contents(__DIR__.'/../../Fixtures/xml/fiscal_document/factura.xml')),
    );

    $result = $this->persist->handle($this->company, $this->parser->parse($xml), $xml);

    expect($result->fiscal_document->totals_mismatch)->toBeTrue()
        ->and($result->fiscal_document->total_cents)->toBe(10_400);
});

it('no colisiona la misma clave entre empresas del mismo tenant', function (): void {
    $other = Company::factory()->create(['tenant_id' => $this->tenant->id, 'ruc' => '0999999999001']);
    $key = AccessKeyFactory::make();
    $xml = str_replace('{{CLAVE}}', $key, (string) file_get_contents(__DIR__.'/../../Fixtures/xml/fiscal_document/factura.xml'));
    $data = $this->parser->parse($xml);

    $first = $this->persist->handle($this->company, $data, $xml);
    $second = $this->persist->handle($other, $data, $xml);

    expect($second->outcome)->toBe(SriImportOutcome::Created)
        ->and($second->fiscal_document->id)->not->toBe($first->fiscal_document->id)
        ->and(FiscalDocument::query()->count())->toBe(2);
});

it('normaliza el RUC del emisor quitando espacios y guiones', function (): void {
    $xml = str_replace(
        '<ruc>1758871873001</ruc>',
        '<ruc>1758871873 001</ruc>',
        fiscal_documentXml('fiscal_document/factura.xml', AccessKeyFactory::make()),
    );

    $result = persistFixture($this, 'fiscal_document/factura.xml', $xml);

    expect($result->fiscal_document->issuer_ruc)->toBe('1758871873001')
        ->and($result->fiscal_document->direction)->toBe(FiscalDocument::DIRECTION_RECEIVED);
});

it('guarda la ruta del XML por ambiente, ruc y período', function (): void {
    $result = persistFixture($this, 'fiscal_document/factura.xml');

    expect($result->fiscal_document->xml_path)->toBe(sprintf('2/1758871873001/2026-09/%s.xml', $result->fiscal_document->access_key))
        ->and($result->fiscal_document->xml_sha256)->toHaveLength(64);
});
