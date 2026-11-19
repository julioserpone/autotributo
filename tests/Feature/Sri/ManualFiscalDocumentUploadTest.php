<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\FiscalDocuments\Models\FiscalDocumentItem;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Testing\TestResponse;
use Spatie\Permission\Models\Role;
use Tests\Support\Sri\AccessKeyFactory;
use Tests\TestCase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'manual-upload']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '1758871873001',
    ]);

    $makeUser = function (MembershipRole $role): User {
        app(TenantContext::class)->initialize($this->tenant);

        $user = User::factory()->create();

        $user->assignRole(Role::firstOrCreate([
            'name' => $role->value,
            'guard_name' => 'web',
            'tenant_id' => $this->tenant->id,
        ]));

        return $user;
    };

    $this->owner = $makeUser(MembershipRole::Owner);
    $this->assistant = $makeUser(MembershipRole::Assistant);
    $this->viewer = $makeUser(MembershipRole::Viewer);
});

function manualFiscalDocumentUrl(Company $company): string
{
    return '/api/v1/companies/'.$company->public_id.'/fiscal_documents/manual';
}

function manualFiscalDocumentXml(string $fixture, string $key): string
{
    $xml = file_get_contents(__DIR__.'/../../Fixtures/xml/'.$fixture);

    if ($xml === false) {
        throw new RuntimeException("No se pudo leer el fixture {$fixture}.");
    }

    return str_replace('{{CLAVE}}', $key, $xml);
}

function uploadManualFiscalDocument(TestCase $test, Company $company, string $xml): TestResponse
{
    return $test->actingAs($test->owner)->post(
        manualFiscalDocumentUrl($company),
        ['file' => UploadedFile::fake()->createWithContent('factura.xml', $xml)],
        ['Accept' => 'application/json'],
    );
}

it('importa un XML autorizado con source manual_upload', function (): void {
    $key = AccessKeyFactory::make();
    $xml = manualFiscalDocumentXml('fiscal_document/factura.xml', $key);

    $response = uploadManualFiscalDocument($this, $this->company, $xml);

    $response->assertCreated()
        ->assertJsonPath('data.source', FiscalDocument::SOURCE_MANUAL_UPLOAD)
        ->assertJsonPath('data.access_key', $key)
        ->assertJsonPath('data.totals_mismatch', false)
        ->assertJsonPath('meta.outcome', 'created');

    expect(FiscalDocument::query()->count())->toBe(1)
        ->and(FiscalDocumentItem::query()->count())->toBe(2)
        ->and(FiscalDocument::query()->firstOrFail()->source)->toBe(FiscalDocument::SOURCE_MANUAL_UPLOAD);
});

it('es idempotente al subir dos veces el mismo XML', function (): void {
    $xml = manualFiscalDocumentXml('fiscal_document/factura.xml', AccessKeyFactory::make());

    uploadManualFiscalDocument($this, $this->company, $xml)->assertCreated();

    uploadManualFiscalDocument($this, $this->company, $xml)
        ->assertOk()
        ->assertJsonPath('meta.outcome', 'skipped');

    expect(FiscalDocument::query()->count())->toBe(1)
        ->and(FiscalDocumentItem::query()->count())->toBe(2);
});

it('marca los totales inconsistentes sin corregirlos', function (): void {
    $xml = str_replace(
        '<importeTotal>103.50</importeTotal>',
        '<importeTotal>104.00</importeTotal>',
        manualFiscalDocumentXml('fiscal_document/factura.xml', AccessKeyFactory::make()),
    );

    uploadManualFiscalDocument($this, $this->company, $xml)
        ->assertCreated()
        ->assertJsonPath('data.totals_mismatch', true)
        ->assertJsonPath('data.total_cents', 10_400);
});

it('rechaza un XML malformado sin persistir nada', function (): void {
    uploadManualFiscalDocument($this, $this->company, '<factura>')
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://autotributo.com/problems/invalid-sri-xml');

    expect(FiscalDocument::query()->count())->toBe(0);
});

it('rechaza una clave de acceso con verificador inválido', function (): void {
    $xml = manualFiscalDocumentXml('fiscal_document/factura.xml', AccessKeyFactory::withBrokenCheckDigit());

    uploadManualFiscalDocument($this, $this->company, $xml)
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json');

    expect(FiscalDocument::query()->count())->toBe(0);
});

it('rechaza un XML con declaraciones XXE', function (): void {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE factura [ <!ENTITY xxe SYSTEM "file:///etc/passwd"> ]>
<factura><infoTributaria><ruc>&xxe;</ruc></infoTributaria></factura>
XML;

    uploadManualFiscalDocument($this, $this->company, $xml)
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://autotributo.com/problems/invalid-sri-xml')
        ->assertJsonPath('detail', 'El XML contiene declaraciones no permitidas.');

    expect(FiscalDocument::query()->count())->toBe(0);
});

it('rechaza archivos que superan el límite de tamaño', function (): void {
    $xml = str_repeat('A', 2_100_000);

    uploadManualFiscalDocument($this, $this->company, $xml)
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(FiscalDocument::query()->count())->toBe(0);
});

it('aísla la carga entre tenants', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'otro-manual']);
    app(TenantContext::class)->initialize($otherTenant);

    $foreign = Company::factory()->create([
        'tenant_id' => $otherTenant->id,
        'ruc' => '1790012345001',
    ]);

    app(TenantContext::class)->initialize($this->tenant);

    $this->actingAs($this->owner)
        ->post(
            manualFiscalDocumentUrl($foreign),
            ['file' => UploadedFile::fake()->createWithContent('factura.xml', manualFiscalDocumentXml('fiscal_document/factura.xml', AccessKeyFactory::make()))],
            ['Accept' => 'application/json'],
        )
        ->assertNotFound();
});

it('permite la carga a assistant y la bloquea a viewer', function (): void {
    $xml = manualFiscalDocumentXml('fiscal_document/factura.xml', AccessKeyFactory::make());

    $this->actingAs($this->viewer)
        ->post(
            manualFiscalDocumentUrl($this->company),
            ['file' => UploadedFile::fake()->createWithContent('factura.xml', $xml)],
            ['Accept' => 'application/json'],
        )
        ->assertForbidden();

    $this->actingAs($this->assistant)
        ->post(
            manualFiscalDocumentUrl($this->company),
            ['file' => UploadedFile::fake()->createWithContent('factura.xml', $xml)],
            ['Accept' => 'application/json'],
        )
        ->assertCreated();

    expect(FiscalDocument::query()->count())->toBe(1);
});
