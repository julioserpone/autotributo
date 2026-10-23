<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Actions\ReconcileSriRun;
use App\Domain\Sri\Actions\ReconcileSriRuns;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriSyncRunFileStatus;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Sri\ReconcileFixtures;

uses(RefreshDatabase::class);

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
    $this->action = app(ReconcileSriRun::class);
});

afterEach(function (): void {
    $this->context->end();
});

it('reconcilia manifiesto, TXT y claves y deja el run listo para parsing', function (): void {
    $run = ReconcileFixtures::run($this->company);
    $path = ReconcileFixtures::path($this->company, '2026-09');
    $txt = "contenido del TXT\n";
    $keys = [str_repeat('1', 49), str_repeat('2', 49)];

    ReconcileFixtures::putPeriod($this->company, '2026-09', SriDocumentType::Invoice, $txt, $keys);

    $result = $this->action->handle($run);
    $manifestFile = $result->files()->where('s3_key', $path->manifest())->firstOrFail();
    $txtFile = $result->files()->where('s3_key', $path->textReport(SriDocumentType::Invoice))->firstOrFail();
    $keysFile = $result->files()->where('s3_key', $path->accessKeys(SriDocumentType::Invoice))->firstOrFail();
    $keysJson = json_encode(
        ReconcileFixtures::keysPayload($this->company, '2026-09', SriDocumentType::Invoice, $keys),
        JSON_THROW_ON_ERROR,
    );

    expect($result->status)->toBe(SriSyncRunStatus::Parsing)
        ->and($result->files()->count())->toBe(3)
        ->and($manifestFile->status)->toBe(SriSyncRunFileStatus::Available)
        ->and($manifestFile->document_type)->toBeNull()
        ->and(strlen((string) $manifestFile->checksum))->toBe(64)
        ->and($txtFile->status)->toBe(SriSyncRunFileStatus::Available)
        ->and($txtFile->document_type)->toBe(SriDocumentType::Invoice)
        ->and($txtFile->checksum)->toBe(hash('sha256', $txt))
        ->and($keysFile->status)->toBe(SriSyncRunFileStatus::Available)
        ->and($keysFile->document_type)->toBe(SriDocumentType::Invoice)
        ->and($keysFile->checksum)->toBe(hash('sha256', $keysJson));
});

it('mantiene el run en downloading mientras falta el TXT', function (): void {
    $run = ReconcileFixtures::run($this->company);
    $path = ReconcileFixtures::path($this->company, '2026-09');

    ReconcileFixtures::putPeriod($this->company, '2026-09', withTxt: false);

    $result = $this->action->handle($run);

    expect($result->status)->toBe(SriSyncRunStatus::Downloading)
        ->and($result->files()->count())->toBe(3)
        ->and($result->files()->where('s3_key', $path->manifest())->firstOrFail()->status)
        ->toBe(SriSyncRunFileStatus::Available)
        ->and($result->files()->where('s3_key', $path->textReport(SriDocumentType::Invoice))->firstOrFail()->status)
        ->toBe(SriSyncRunFileStatus::Missing)
        ->and($result->files()->where('s3_key', $path->accessKeys(SriDocumentType::Invoice))->firstOrFail()->status)
        ->toBe(SriSyncRunFileStatus::Available);
});

it('no cambia el run si el robot todavía no publicó el manifiesto', function (): void {
    $run = ReconcileFixtures::run($this->company);

    $result = $this->action->handle($run);

    expect($result->status)->toBe(SriSyncRunStatus::Invoking)
        ->and($result->files()->count())->toBe(0);
});

it('marca el JSON de claves inválido y no deja el run en parsing', function (): void {
    $run = ReconcileFixtures::run($this->company);
    $path = ReconcileFixtures::path($this->company, '2026-09');

    ReconcileFixtures::putPeriod($this->company, '2026-09', withKeys: false);
    Storage::disk('sri')->put($path->accessKeys(SriDocumentType::Invoice), '{invalido');

    $result = $this->action->handle($run);
    $keysFile = $result->files()->where('s3_key', $path->accessKeys(SriDocumentType::Invoice))->firstOrFail();

    expect($result->status)->toBe(SriSyncRunStatus::Downloading)
        ->and($keysFile->status)->toBe(SriSyncRunFileStatus::Invalid)
        ->and($keysFile->error_message)->not->toBeNull();
});

it('marca el TXT inválido cuando no coincide con los bytes del manifiesto', function (): void {
    $run = ReconcileFixtures::run($this->company);
    $path = ReconcileFixtures::path($this->company, '2026-09');
    $txt = "contenido del TXT\n";
    $keys = [str_repeat('1', 49), str_repeat('2', 49)];

    ReconcileFixtures::putPeriod($this->company, '2026-09', SriDocumentType::Invoice, $txt, $keys);
    $manifest = ReconcileFixtures::manifest($this->company, '2026-09', SriDocumentType::Invoice, $txt, $keys);
    $manifest['descargas'][0]['bytes'] = strlen($txt) + 1;
    Storage::disk('sri')->put($path->manifest(), json_encode($manifest, JSON_THROW_ON_ERROR));

    $result = $this->action->handle($run);
    $txtFile = $result->files()->where('s3_key', $path->textReport(SriDocumentType::Invoice))->firstOrFail();

    expect($result->status)->toBe(SriSyncRunStatus::Downloading)
        ->and($txtFile->status)->toBe(SriSyncRunFileStatus::Invalid)
        ->and($txtFile->error_message)->not->toBeNull();
});

it('rechaza un manifiesto que no corresponde a la empresa del run', function (): void {
    $run = ReconcileFixtures::run($this->company);
    $path = ReconcileFixtures::path($this->company, '2026-09');
    $txt = "contenido del TXT\n";
    $keys = [str_repeat('1', 49), str_repeat('2', 49)];

    ReconcileFixtures::putPeriod($this->company, '2026-09', SriDocumentType::Invoice, $txt, $keys);
    $manifest = ReconcileFixtures::manifest($this->company, '2026-09', SriDocumentType::Invoice, $txt, $keys);
    $manifest['ruc'] = '0999999999001';
    Storage::disk('sri')->put($path->manifest(), json_encode($manifest, JSON_THROW_ON_ERROR));

    $result = $this->action->handle($run);
    $manifestFile = $result->files()->where('s3_key', $path->manifest())->firstOrFail();

    expect($result->status)->toBe(SriSyncRunStatus::Downloading)
        ->and($result->files()->count())->toBe(1)
        ->and($manifestFile->status)->toBe(SriSyncRunFileStatus::Invalid);
});

it('es idempotente al reconciliar el mismo run dos veces', function (): void {
    $run = ReconcileFixtures::run($this->company);
    ReconcileFixtures::putPeriod($this->company, '2026-09');

    $first = $this->action->handle($run);
    $second = $this->action->handle($first);

    expect($first->status)->toBe(SriSyncRunStatus::Parsing)
        ->and($second->status)->toBe(SriSyncRunStatus::Parsing)
        ->and($second->files()->count())->toBe(3);
});

it('reconcilia todos los períodos del run', function (): void {
    $run = ReconcileFixtures::run($this->company, ['2026-08', '2026-09']);
    ReconcileFixtures::putPeriod($this->company, '2026-08');
    ReconcileFixtures::putPeriod($this->company, '2026-09');

    $result = $this->action->handle($run);

    expect($result->status)->toBe(SriSyncRunStatus::Parsing)
        ->and($result->files()->count())->toBe(6);
});

it('usa el rango de fechas como respaldo para runs antiguos sin períodos', function (): void {
    $run = ReconcileFixtures::run($this->company, []);
    ReconcileFixtures::putPeriod($this->company, '2026-09');

    $result = $this->action->handle($run);

    expect($result->status)->toBe(SriSyncRunStatus::Parsing)
        ->and($result->files()->count())->toBe(3);
});

it('reconcilia solo runs activos y respeta el límite', function (): void {
    $first = ReconcileFixtures::run($this->company, ['2026-08']);
    $second = ReconcileFixtures::run($this->company, ['2026-09']);
    ReconcileFixtures::run($this->company, ['2026-07'], status: SriSyncRunStatus::Completed);

    ReconcileFixtures::putPeriod($this->company, '2026-08');
    ReconcileFixtures::putPeriod($this->company, '2026-09');

    $action = app(ReconcileSriRuns::class);
    $limited = $action->handle(1);

    expect($limited)->toHaveCount(1)
        ->and($limited[0]->id)->toBe($first->id)
        ->and($limited[0]->status)->toBe(SriSyncRunStatus::Parsing);

    $all = $action->handle(10);

    expect($all)->toHaveCount(2)
        ->and($second->refresh()->status)->toBe(SriSyncRunStatus::Parsing);
});

it('ejecuta el comando de reconciliación con el tenant activo', function (): void {
    $run = ReconcileFixtures::run($this->company);
    ReconcileFixtures::putPeriod($this->company, '2026-09');

    $this->artisan('sri:reconcile-runs')
        ->expectsOutput('Runs reconciliados: 1')
        ->assertSuccessful();

    expect($run->refresh()->status)->toBe(SriSyncRunStatus::Parsing);
});

it('inicializa el tenant por defecto cuando el comando corre sin contexto', function (): void {
    $run = ReconcileFixtures::run($this->company);
    ReconcileFixtures::putPeriod($this->company, '2026-09');
    $this->tenant->update(['slug' => config('tenancy.default_slug')]);
    $this->context->end();

    $this->artisan('sri:reconcile-runs')->assertSuccessful();

    expect($run->refresh()->status)->toBe(SriSyncRunStatus::Parsing);
});
