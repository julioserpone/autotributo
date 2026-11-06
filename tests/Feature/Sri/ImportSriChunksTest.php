<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Sri\Actions\ImportSriKeys;
use App\Domain\Sri\Contracts\SriAuthorizationGateway;
use App\Domain\Sri\Contracts\SriXmlRecovery;
use App\Domain\Sri\Data\AuthorizationQuery;
use App\Domain\Sri\Data\AuthorizationResult;
use App\Domain\Sri\Enums\AuthorizationStatus;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriImportOutcome;
use App\Domain\Sri\Enums\SriSyncRunFileStatus;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Exceptions\SriGatewayException;
use App\Domain\Sri\Jobs\ImportSriChunkJob;
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
    // Dentro de la ventana de 45 días: el flujo consulta primero al SRI.
    $this->travelTo(CarbonImmutable::parse('2026-10-01 12:00:00'));
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

    $this->template = (string) file_get_contents(__DIR__.'/../../Fixtures/xml/invoice/factura.xml');
});

afterEach(function (): void {
    $this->context->end();
});

/**
 * Instala un gateway falso con XML válido, respuestas vacías o XML corrupto.
 *
 * @param  list<string>  $keys
 * @param  list<string>  $unavailable
 * @param  list<string>  $corrupt
 */
function bindChunkGateway(array $keys, array $unavailable = [], array $corrupt = []): void
{
    $template = (string) file_get_contents(__DIR__.'/../../Fixtures/xml/invoice/factura.xml');
    $results = [];

    foreach ($keys as $key) {
        if (in_array($key, $unavailable, true)) {
            $results[$key] = AuthorizationResult::notFound($key, 'NO AUTORIZADO');

            continue;
        }

        $results[$key] = new AuthorizationResult(
            accessKey: $key,
            status: AuthorizationStatus::Authorized,
            authorizationNumber: $key,
            xml: in_array($key, $corrupt, true)
                ? '<factura><infoTributaria>'
                : str_replace('{{CLAVE}}', $key, $template),
        );
    }

    app()->instance(SriAuthorizationGateway::class, new FakeSriAuthorizationGateway($results));
}

function parsingRun(Company $company): SriSyncRun
{
    return ReconcileFixtures::run($company, status: SriSyncRunStatus::Parsing);
}

it('registra el resultado de cada comprobante del bloque', function (): void {
    bindChunkGateway($this->keys);
    $run = parsingRun($this->company);

    $result = app(ImportSriKeys::class)->handle($run, SriDocumentType::Invoice, '2026-09', $this->keys);

    expect($result->found)->toBe(3)
        ->and($result->imported)->toBe(3)
        ->and($result->failed)->toBe(0)
        ->and($result->fromSri)->toBe(3)
        ->and($result->fromS3)->toBe(0)
        ->and(Invoice::query()->count())->toBe(3);

    $files = $run->files()->where('s3_key', 'like', '%-xml/%')->get();

    expect($files)->toHaveCount(3)
        ->and($files->every(
            static fn ($file): bool => $file->status === SriSyncRunFileStatus::Processed
                && $file->import_outcome === SriImportOutcome::Created
                && $file->source === 'sri'
                && $file->error_code === null,
        ))->toBeTrue();
});

it('marca los fallos por comprobante sin detener el resto del bloque', function (): void {
    bindChunkGateway(
        $this->keys,
        unavailable: [$this->keys[1]],
        corrupt: [$this->keys[2]],
    );
    $run = parsingRun($this->company);

    $result = app(ImportSriKeys::class)->handle($run, SriDocumentType::Invoice, '2026-09', $this->keys);

    expect($result->found)->toBe(3)
        ->and($result->imported)->toBe(1)
        ->and($result->failed)->toBe(2)
        ->and(Invoice::query()->count())->toBe(1);

    $missing = $run->files()->where('file_name', $this->keys[1].'.xml')->sole();
    $invalid = $run->files()->where('file_name', $this->keys[2].'.xml')->sole();

    expect($missing->status)->toBe(SriSyncRunFileStatus::Invalid)
        ->and($missing->error_code)->toBe('invalid_xml')
        ->and($missing->import_outcome)->toBeNull()
        ->and($invalid->status)->toBe(SriSyncRunFileStatus::Invalid)
        ->and($invalid->error_code)->toBe('invalid_xml');
});

it('marca sri_unavailable cuando el gateway del SRI falla', function (): void {
    app()->instance(SriAuthorizationGateway::class, new ChunkGatewayCaido);
    $run = parsingRun($this->company);

    $result = app(ImportSriKeys::class)->handle(
        $run,
        SriDocumentType::Invoice,
        '2026-09',
        [$this->keys[0]],
    );

    $file = $run->files()->where('file_name', $this->keys[0].'.xml')->sole();

    expect($result->failed)->toBe(1)
        ->and($file->status)->toBe(SriSyncRunFileStatus::Missing)
        ->and($file->error_code)->toBe('sri_unavailable');
});

it('lee del S3 del robot y registra la fuente', function (): void {
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00'));

    ReconcileFixtures::putPeriod(
        $this->company,
        '2026-09',
        SriDocumentType::Invoice,
        implode("\n", $this->keys)."\n",
        $this->keys,
    );

    foreach ($this->keys as $key) {
        ReconcileFixtures::putXml($this->company, '2026-09', $key, str_replace('{{CLAVE}}', $key, $this->template));
    }

    $run = parsingRun($this->company);
    app(SriXmlRecovery::class)->discover($run);
    app()->instance(SriAuthorizationGateway::class, new FakeSriAuthorizationGateway);

    $result = app(ImportSriKeys::class)->handle($run, SriDocumentType::Invoice, '2026-09', $this->keys);

    expect($result->imported)->toBe(3)
        ->and($result->fromS3)->toBe(3)
        ->and($result->fromSri)->toBe(0)
        ->and(Invoice::query()->count())->toBe(3);

    expect($run->files()->where('source', 's3')->count())->toBe(3);
});

it('el job de bloque importa solo las claves recibidas', function (): void {
    bindChunkGateway($this->keys);
    $run = parsingRun($this->company);

    (new ImportSriChunkJob(
        $run->id,
        SriDocumentType::Invoice->value,
        '2026-09',
        [$this->keys[0]],
    ))->handle();

    expect(Invoice::query()->count())->toBe(1)
        ->and($run->files()->where('file_name', $this->keys[0].'.xml')->exists())->toBeTrue();
});

it('el job de bloque omite un run que ya no está en parsing', function (): void {
    bindChunkGateway($this->keys);
    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Completed);

    (new ImportSriChunkJob(
        $run->id,
        SriDocumentType::Invoice->value,
        '2026-09',
        $this->keys,
    ))->handle();

    expect(Invoice::query()->count())->toBe(0);
});

/**
 * Gateway que siempre falla a nivel de integración, para distinguir
 * `sri_unavailable` de un XML ilegible.
 */
final class ChunkGatewayCaido implements SriAuthorizationGateway
{
    public function query(AuthorizationQuery $query): AuthorizationResult
    {
        throw SriGatewayException::authorizationUnavailable(new RuntimeException('SRI caído'));
    }
}
