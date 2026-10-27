<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Sri\Actions\ImportSriRun;
use App\Domain\Sri\Actions\ReconcileSriRun;
use App\Domain\Sri\Contracts\SriAuthorizationGateway;
use App\Domain\Sri\Data\AuthorizationResult;
use App\Domain\Sri\Enums\AuthorizationStatus;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Integrations\Sri\FakeSriAuthorizationGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Sri\AccessKeyFactory;
use Tests\Support\Sri\ReconcileFixtures;

uses(RefreshDatabase::class);

/**
 * Publica el paquete del robot para un período con las claves indicadas.
 *
 * @param  list<string>  $keys
 */
function publishKeys(Company $company, string $period, array $keys, SriDocumentType $type = SriDocumentType::Invoice): void
{
    ReconcileFixtures::putPeriod($company, $period, $type, implode("\n", $keys)."\n", $keys);
}

function runInParsing(Company $company): SriSyncRun
{
    $run = ReconcileFixtures::run($company);

    return app(ReconcileSriRun::class)->handle($run);
}

beforeEach(function (): void {
    Storage::fake('sri');
    config(['sri.robot.environment' => 'dev']);

    $this->tenant = Tenant::factory()->create();
    $this->context = app(TenantContext::class);
    $this->context->initialize($this->tenant);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '1758871873001',
    ]);

    $this->keys = [
        AccessKeyFactory::make(sequential: '000000001'),
        AccessKeyFactory::make(sequential: '000000002'),
        AccessKeyFactory::make(sequential: '000000003'),
    ];
});

afterEach(function (): void {
    $this->context->end();
});

/**
 * Instala un gateway falso que devuelve el XML de factura por clave.
 *
 * @param  list<string>  $keys
 * @param  list<string>  $unavailable
 */
function bindGateway(array $keys, array $unavailable = [], string $fixture = 'invoice/factura.xml'): void
{
    $template = (string) file_get_contents(__DIR__.'/../../Fixtures/xml/'.$fixture);
    $results = [];

    foreach ($keys as $key) {
        $results[$key] = in_array($key, $unavailable, true)
            ? AuthorizationResult::notFound($key, 'NO AUTORIZADO')
            : new AuthorizationResult(
                accessKey: $key,
                status: AuthorizationStatus::Authorized,
                authorizationNumber: $key,
                xml: str_replace('{{CLAVE}}', $key, $template),
            );
    }

    app()->instance(SriAuthorizationGateway::class, new FakeSriAuthorizationGateway($results));
}

it('importa todos los comprobantes de un run', function (): void {
    publishKeys($this->company, '2026-09', $this->keys);
    bindGateway($this->keys);
    $run = runInParsing($this->company);

    $result = app(ImportSriRun::class)->handle($run);

    expect($result['run']->status)->toBe(SriSyncRunStatus::Completed)
        ->and($result['report']->found)->toBe(3)
        ->and($result['report']->imported)->toBe(3)
        ->and($result['report']->failed)->toBe(0)
        ->and(Invoice::query()->count())->toBe(3)
        ->and($result['run']->totals['imported'])->toBe(3)
        ->and($result['run']->error_code)->toBeNull();
});

it('no duplica comprobantes al reimportar el mismo run', function (): void {
    publishKeys($this->company, '2026-09', $this->keys);
    bindGateway($this->keys);
    $run = runInParsing($this->company);

    app(ImportSriRun::class)->handle($run);

    $again = runInParsing($this->company);
    $again->status = SriSyncRunStatus::Parsing;
    $again->save();

    $result = app(ImportSriRun::class)->handle($again);

    expect($result['report']->skipped)->toBe(3)
        ->and($result['report']->imported)->toBe(0)
        ->and(Invoice::query()->count())->toBe(3);
});

it('deja partial cuando alguna clave no está disponible', function (): void {
    publishKeys($this->company, '2026-09', $this->keys);
    bindGateway($this->keys, [$this->keys[1]]);
    $run = runInParsing($this->company);

    $result = app(ImportSriRun::class)->handle($run);

    expect($result['run']->status)->toBe(SriSyncRunStatus::Partial)
        ->and($result['report']->imported)->toBe(2)
        ->and($result['report']->failed)->toBe(1)
        ->and(Invoice::query()->count())->toBe(2)
        ->and($result['run']->error_message)->toContain('1 comprobante');
});

it('falla el run cuando ninguna clave se puede importar', function (): void {
    publishKeys($this->company, '2026-09', $this->keys);
    bindGateway($this->keys, $this->keys);
    $run = runInParsing($this->company);

    $result = app(ImportSriRun::class)->handle($run);

    expect($result['run']->status)->toBe(SriSyncRunStatus::Failed)
        ->and($result['run']->error_code)->toBe('import_failed')
        ->and(Invoice::query()->count())->toBe(0);
});

it('un XML corrupto no tumba el resto del run', function (): void {
    publishKeys($this->company, '2026-09', $this->keys);

    $template = (string) file_get_contents(__DIR__.'/../../Fixtures/xml/invoice/factura.xml');
    app()->instance(SriAuthorizationGateway::class, new FakeSriAuthorizationGateway([
        $this->keys[0] => new AuthorizationResult(
            accessKey: $this->keys[0],
            status: AuthorizationStatus::Authorized,
            xml: '<factura><infoTributaria>',
        ),
        $this->keys[1] => new AuthorizationResult(
            accessKey: $this->keys[1],
            status: AuthorizationStatus::Authorized,
            xml: str_replace('{{CLAVE}}', $this->keys[1], $template),
        ),
        $this->keys[2] => new AuthorizationResult(
            accessKey: $this->keys[2],
            status: AuthorizationStatus::Authorized,
            xml: str_replace('{{CLAVE}}', $this->keys[2], $template),
        ),
    ]));

    $result = app(ImportSriRun::class)->handle(runInParsing($this->company));

    expect($result['run']->status)->toBe(SriSyncRunStatus::Partial)
        ->and($result['report']->imported)->toBe(2)
        ->and($result['report']->failed)->toBe(1);
});

it('no procesa un run que no está listo', function (): void {
    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Queued);

    $result = app(ImportSriRun::class)->handle($run);

    expect($result['run']->status)->toBe(SriSyncRunStatus::Queued)
        ->and($result['report']->found)->toBe(0);
});

it('se ejecuta desde el comando con un run en parsing', function (): void {
    publishKeys($this->company, '2026-09', $this->keys);
    bindGateway($this->keys);
    $run = runInParsing($this->company);

    $this->artisan('sri:import-run', ['run' => $run->public_id])
        ->expectsOutputToContain('completed')
        ->assertSuccessful();

    expect(Invoice::query()->count())->toBe(3);
});

it('importa un comprobante de retención desde el run', function (): void {
    $keys = [AccessKeyFactory::make(type: '07', sequential: '000000004')];
    publishKeys($this->company, '2026-09', $keys, SriDocumentType::Withholding);
    bindGateway($keys, fixture: 'retention/comprobante-retencion.xml');

    $run = ReconcileFixtures::run($this->company, documentTypes: ['07']);
    $run = app(ReconcileSriRun::class)->handle($run);

    app(ImportSriRun::class)->handle($run);

    $invoice = Invoice::query()->sole();

    expect($invoice->document_type)->toBe(SriDocumentType::Withholding)
        ->and($invoice->retentions()->count())->toBe(2);
});
