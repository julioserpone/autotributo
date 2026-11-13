<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Invoicing\Models\InvoiceItem;
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

function manualInvoiceUrl(Company $company): string
{
    return '/api/v1/companies/'.$company->public_id.'/invoices/manual';
}

function manualInvoiceXml(string $fixture, string $key): string
{
    $xml = file_get_contents(__DIR__.'/../../Fixtures/xml/'.$fixture);

    if ($xml === false) {
        throw new RuntimeException("No se pudo leer el fixture {$fixture}.");
    }

    return str_replace('{{CLAVE}}', $key, $xml);
}

function uploadManualInvoice(TestCase $test, Company $company, string $xml): TestResponse
{
    return $test->actingAs($test->owner)->post(
        manualInvoiceUrl($company),
        ['file' => UploadedFile::fake()->createWithContent('factura.xml', $xml)],
        ['Accept' => 'application/json'],
    );
}

it('importa un XML autorizado con source manual_upload', function (): void {
    $key = AccessKeyFactory::make();
    $xml = manualInvoiceXml('invoice/factura.xml', $key);

    $response = uploadManualInvoice($this, $this->company, $xml);

    $response->assertCreated()
        ->assertJsonPath('data.source', Invoice::SOURCE_MANUAL_UPLOAD)
        ->assertJsonPath('data.access_key', $key)
        ->assertJsonPath('data.totals_mismatch', false)
        ->assertJsonPath('meta.outcome', 'created');

    expect(Invoice::query()->count())->toBe(1)
        ->and(InvoiceItem::query()->count())->toBe(2)
        ->and(Invoice::query()->firstOrFail()->source)->toBe(Invoice::SOURCE_MANUAL_UPLOAD);
});

it('es idempotente al subir dos veces el mismo XML', function (): void {
    $xml = manualInvoiceXml('invoice/factura.xml', AccessKeyFactory::make());

    uploadManualInvoice($this, $this->company, $xml)->assertCreated();

    uploadManualInvoice($this, $this->company, $xml)
        ->assertOk()
        ->assertJsonPath('meta.outcome', 'skipped');

    expect(Invoice::query()->count())->toBe(1)
        ->and(InvoiceItem::query()->count())->toBe(2);
});

it('marca los totales inconsistentes sin corregirlos', function (): void {
    $xml = str_replace(
        '<importeTotal>103.50</importeTotal>',
        '<importeTotal>104.00</importeTotal>',
        manualInvoiceXml('invoice/factura.xml', AccessKeyFactory::make()),
    );

    uploadManualInvoice($this, $this->company, $xml)
        ->assertCreated()
        ->assertJsonPath('data.totals_mismatch', true)
        ->assertJsonPath('data.total_cents', 10_400);
});

it('rechaza un XML malformado sin persistir nada', function (): void {
    uploadManualInvoice($this, $this->company, '<factura>')
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://autotributo.com/problems/invalid-sri-xml');

    expect(Invoice::query()->count())->toBe(0);
});

it('rechaza una clave de acceso con verificador inválido', function (): void {
    $xml = manualInvoiceXml('invoice/factura.xml', AccessKeyFactory::withBrokenCheckDigit());

    uploadManualInvoice($this, $this->company, $xml)
        ->assertStatus(422)
        ->assertHeader('Content-Type', 'application/problem+json');

    expect(Invoice::query()->count())->toBe(0);
});

it('rechaza un XML con declaraciones XXE', function (): void {
    $xml = <<<'XML'
<?xml version="1.0" encoding="UTF-8"?>
<!DOCTYPE factura [ <!ENTITY xxe SYSTEM "file:///etc/passwd"> ]>
<factura><infoTributaria><ruc>&xxe;</ruc></infoTributaria></factura>
XML;

    uploadManualInvoice($this, $this->company, $xml)
        ->assertStatus(422)
        ->assertJsonPath('type', 'https://autotributo.com/problems/invalid-sri-xml')
        ->assertJsonPath('detail', 'El XML contiene declaraciones no permitidas.');

    expect(Invoice::query()->count())->toBe(0);
});

it('rechaza archivos que superan el límite de tamaño', function (): void {
    $xml = str_repeat('A', 2_100_000);

    uploadManualInvoice($this, $this->company, $xml)
        ->assertStatus(422)
        ->assertJsonValidationErrors('file');

    expect(Invoice::query()->count())->toBe(0);
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
            manualInvoiceUrl($foreign),
            ['file' => UploadedFile::fake()->createWithContent('factura.xml', manualInvoiceXml('invoice/factura.xml', AccessKeyFactory::make()))],
            ['Accept' => 'application/json'],
        )
        ->assertNotFound();
});

it('permite la carga a assistant y la bloquea a viewer', function (): void {
    $xml = manualInvoiceXml('invoice/factura.xml', AccessKeyFactory::make());

    $this->actingAs($this->viewer)
        ->post(
            manualInvoiceUrl($this->company),
            ['file' => UploadedFile::fake()->createWithContent('factura.xml', $xml)],
            ['Accept' => 'application/json'],
        )
        ->assertForbidden();

    $this->actingAs($this->assistant)
        ->post(
            manualInvoiceUrl($this->company),
            ['file' => UploadedFile::fake()->createWithContent('factura.xml', $xml)],
            ['Accept' => 'application/json'],
        )
        ->assertCreated();

    expect(Invoice::query()->count())->toBe(1);
});
