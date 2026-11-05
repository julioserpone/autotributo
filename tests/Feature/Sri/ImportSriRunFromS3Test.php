<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Sri\Actions\ImportSriRun;
use App\Domain\Sri\Actions\RecoverSriRunFromS3;
use App\Domain\Sri\Actions\S3SriXmlRecovery;
use App\Domain\Sri\Contracts\SriAuthorizationGateway;
use App\Domain\Sri\Contracts\SriXmlRecovery;
use App\Domain\Sri\Data\AuthorizationQuery;
use App\Domain\Sri\Data\AuthorizationResult;
use App\Domain\Sri\Enums\AuthorizationStatus;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Exceptions\SriGatewayException;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Integrations\Sri\FakeSriAuthorizationGateway;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Sri\AccessKeyFactory;
use Tests\Support\Sri\ReconcileFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('sri');
    config([
        'sri.robot.environment' => 'dev',
        'sri.authorization.fallback_to_sri' => true,
    ]);

    $this->tenant = Tenant::factory()->create();
    $this->context = app(TenantContext::class);
    $this->context->initialize($this->tenant);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '1758871873001',
    ]);

    // Claves fuera de la ventana del SRI (emitidas hace más de 45 días): el
    // XML solo puede venir de S3.
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00'));
    $this->template = (string) file_get_contents(__DIR__.'/../../Fixtures/xml/invoice/factura.xml');
    $this->keys = [
        AccessKeyFactory::make(sequential: '000000001'),
        AccessKeyFactory::make(sequential: '000000002'),
    ];
});

afterEach(function (): void {
    $this->context->end();
});

/**
 * Publica el lote del robot y el XML de cada clave indicada.
 *
 * @param  list<string>  $withXml
 */
function publishRobotPackage(Company $company, string $period, array $keys, array $withXml, string $template): void
{
    ReconcileFixtures::putPeriod($company, $period, SriDocumentType::Invoice, implode("\n", $keys)."\n", $keys);

    foreach ($withXml as $key) {
        ReconcileFixtures::putXml($company, $period, $key, str_replace('{{CLAVE}}', $key, $template));
    }
}

function sriRun(Company $company, array $periods = ['2026-09']): SriSyncRun
{
    return ReconcileFixtures::run($company, periods: $periods, status: SriSyncRunStatus::Invoking);
}

/**
 * Gateway que cuenta consultas y falla: el flujo no debe necesitarlo cuando
 * todos los XML vienen de S3.
 */
final class GatewayNoDisponible implements SriAuthorizationGateway
{
    public int $calls = 0;

    public function query(AuthorizationQuery $query): AuthorizationResult
    {
        $this->calls++;

        throw SriGatewayException::robotObjectUnreadable();
    }
}

function gatewayQueNoDebeUsarse(): GatewayNoDisponible
{
    $gateway = new GatewayNoDisponible;
    app()->instance(SriAuthorizationGateway::class, $gateway);

    return $gateway;
}

it('importa los XML desde S3 sin consultar el SRI', function (): void {
    publishRobotPackage($this->company, '2026-09', $this->keys, $this->keys, $this->template);
    $gateway = gatewayQueNoDebeUsarse();

    $run = app(RecoverSriRunFromS3::class)->handle(sriRun($this->company));
    $result = app(ImportSriRun::class)->handle($run);

    expect($gateway->calls)->toBe(0)
        ->and($result['run']->status)->toBe(SriSyncRunStatus::Completed)
        ->and($result['report']->imported)->toBe(2)
        ->and($result['report']->failed)->toBe(0)
        ->and(Invoice::query()->count())->toBe(2)
        ->and($result['run']->totals['from_s3'])->toBe(2);

    $files = $result['run']->files()->where('s3_key', 'like', '%-xml/%')->get();

    expect($files)->toHaveCount(2)
        ->and($files->every(static fn ($file): bool => $file->checksum !== null))->toBeTrue();
});

it('completa desde S3 solo las claves que el robot no guardó y consulta el SRI por el resto', function (): void {
    // Claves dentro de la ventana del SRI: primero se consulta al SRI y, si una
    // clave no tiene XML allí, se usa el de S3.
    $this->travelTo(CarbonImmutable::parse('2026-09-17 12:00:00'));
    [$first, $second] = $this->keys;
    publishRobotPackage($this->company, '2026-09', $this->keys, [$first], $this->template);

    // El SRI solo entrega la segunda clave; la primera cae a S3.
    $gateway = new FakeSriAuthorizationGateway([
        $second => new AuthorizationResult(
            accessKey: $second,
            status: AuthorizationStatus::Authorized,
            xml: str_replace('{{CLAVE}}', $second, $this->template),
        ),
    ]);
    app()->instance(SriAuthorizationGateway::class, $gateway);

    $run = app(RecoverSriRunFromS3::class)->handle(sriRun($this->company));
    $result = app(ImportSriRun::class)->handle($run);

    expect($result['report']->imported)->toBe(2)
        ->and($result['run']->totals['from_s3'])->toBe(1)
        ->and(array_map(static fn ($query): string => $query->accessKey, $gateway->queries))->toBe([$first, $second]);
});

it('no consulta el SRI cuando el respaldo está desactivado', function (): void {
    config(['sri.authorization.fallback_to_sri' => false]);
    [$first, $second] = $this->keys;
    publishRobotPackage($this->company, '2026-09', $this->keys, [$first], $this->template);

    $gateway = new FakeSriAuthorizationGateway([
        $first => new AuthorizationResult(
            accessKey: $first,
            status: AuthorizationStatus::Authorized,
            xml: str_replace('{{CLAVE}}', $first, $this->template),
        ),
    ]);
    app()->instance(SriAuthorizationGateway::class, $gateway);

    $run = app(RecoverSriRunFromS3::class)->handle(sriRun($this->company));
    $result = app(ImportSriRun::class)->handle($run);

    expect($result['report']->imported)->toBe(1)
        ->and($result['report']->failed)->toBe(1)
        ->and($gateway->queries)->toBe([]);
});

it('importa desde un run en invoking aunque no haya callback ni reconciliación previa', function (): void {
    publishRobotPackage($this->company, '2026-09', $this->keys, $this->keys, $this->template);
    gatewayQueNoDebeUsarse();

    $result = app(ImportSriRun::class)->handle(sriRun($this->company));

    expect($result['run']->status)->toBe(SriSyncRunStatus::Completed)
        ->and(Invoice::query()->count())->toBe(2);
});

it('no lee XML de un período que el run no pidió aunque exista en el bucket', function (): void {
    // El robot publicó el período 2026-08; el run consulta 2026-09.
    publishRobotPackage($this->company, '2026-08', $this->keys, $this->keys, $this->template);
    gatewayQueNoDebeUsarse();

    $recovered = app(RecoverSriRunFromS3::class)->handle(sriRun($this->company));

    expect($recovered->status)->toBe(SriSyncRunStatus::Invoking)
        ->and($recovered->files()->count())->toBe(0);

    $result = app(ImportSriRun::class)->handle($recovered);

    expect($result['report']->found)->toBe(0)
        ->and(Invoice::query()->count())->toBe(0);
});

it('ignora un manifiesto de otro RUC y no recupera nada', function (): void {
    publishRobotPackage($this->company, '2026-09', $this->keys, $this->keys, $this->template);

    $otra = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '0999999999001',
    ]);
    $run = sriRun($otra);

    $recovered = app(RecoverSriRunFromS3::class)->handle($run);

    expect($recovered->status)->toBe(SriSyncRunStatus::Invoking)
        ->and($recovered->files()->count())->toBe(0);
});

it('marca missing el XML que el lote lista y el robot no guardó', function (): void {
    [$first, $second] = $this->keys;
    publishRobotPackage($this->company, '2026-09', $this->keys, [$first], $this->template);

    $run = app(RecoverSriRunFromS3::class)->handle(sriRun($this->company));
    $missing = $run->files()->where('file_name', $second.'.xml')->firstOrFail();

    expect($missing->status->value)->toBe('missing');
});

it('cierra el run cuando el robot terminó sin comprobantes para el período', function (): void {
    Storage::disk('sri')->put(
        ReconcileFixtures::path($this->company, '2026-09')->manifest(),
        json_encode([
            'ambiente' => 'dev',
            'ruc' => $this->company->ruc,
            'periodo' => '2026-09',
            'generadoEn' => now()->toIso8601String(),
            'descargas' => [],
            'totalClaves' => 0,
        ], JSON_THROW_ON_ERROR),
    );

    $recovered = app(RecoverSriRunFromS3::class)->handle(sriRun($this->company));

    expect($recovered->status)->toBe(SriSyncRunStatus::Failed)
        ->and($recovered->error_code)->toBe('no_documents')
        ->and($recovered->totals['found'])->toBe(0)
        ->and(Invoice::query()->count())->toBe(0);
});

it('el comando sri:recover-run recupera e importa un run sin callback', function (): void {
    publishRobotPackage($this->company, '2026-09', $this->keys, $this->keys, $this->template);
    gatewayQueNoDebeUsarse();

    $run = sriRun($this->company);

    $this->artisan('sri:recover-run', ['run' => $run->public_id])
        ->expectsOutputToContain('completed')
        ->assertSuccessful();

    expect($run->refresh()->status)->toBe(SriSyncRunStatus::Completed)
        ->and(Invoice::query()->count())->toBe(2);
});

it('el comando sri:recover-run informa cuando el paquete todavía no está en S3', function (): void {
    $run = sriRun($this->company);

    $this->artisan('sri:recover-run', ['run' => $run->public_id])
        ->expectsOutputToContain('invoking')
        ->assertFailed();

    expect($run->refresh()->status)->toBe(SriSyncRunStatus::Invoking);
});

it('expone la recuperación como contrato del dominio', function (): void {
    expect(app(SriXmlRecovery::class))->toBeInstanceOf(S3SriXmlRecovery::class);
});

it('consulta primero al SRI cuando el comprobante está dentro de la ventana de días', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-09-30 12:00:00'));
    publishRobotPackage($this->company, '2026-09', $this->keys, $this->keys, $this->template);

    $gateway = gatewayQueNoDebeUsarse();

    $result = app(ImportSriRun::class)->handle(sriRun($this->company));

    // El SRI falla y el flujo cae a S3: dentro de la ventana se intenta SRI
    // primero, pero el XML igual se importa.
    expect($gateway->calls)->toBe(2)
        ->and($result['report']->imported)->toBe(2)
        ->and($result['run']->totals['from_sri'])->toBe(0)
        ->and($result['run']->totals['from_s3'])->toBe(2);
});

it('no consulta al SRI cuando el comprobante está fuera de la ventana', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00'));
    publishRobotPackage($this->company, '2026-09', $this->keys, $this->keys, $this->template);

    $gateway = gatewayQueNoDebeUsarse();

    $result = app(ImportSriRun::class)->handle(sriRun($this->company));

    expect($gateway->calls)->toBe(0)
        ->and($result['report']->imported)->toBe(2);
});

it('la ventana se controla con SRI_AUTHORIZATION_WINDOW_DAYS', function (): void {
    config(['sri.authorization.window_days' => 120]);
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00'));
    publishRobotPackage($this->company, '2026-09', $this->keys, $this->keys, $this->template);

    $gateway = gatewayQueNoDebeUsarse();

    app(ImportSriRun::class)->handle(sriRun($this->company));

    expect($gateway->calls)->toBe(2);
});
