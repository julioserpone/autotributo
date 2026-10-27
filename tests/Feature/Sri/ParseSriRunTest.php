<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Actions\ParseSriRun;
use App\Domain\Sri\Actions\ReconcileSriRun;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriSyncRunFileStatus;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Sri\AccessKeyFactory;
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

    $this->action = app(ParseSriRun::class);

    /**
     * Crea un run y lo deja en `parsing` reconciliando contra S3.
     *
     * @param  list<string>|null  $documentTypes
     */
    $this->parsedRun = function (array $documentTypes = ['01'], ?SriSyncRun $run = null): SriSyncRun {
        $run ??= ReconcileFixtures::run($this->company, documentTypes: $documentTypes);
        $reconciled = app(ReconcileSriRun::class)->handle($run);

        expect($reconciled->status)->toBe(SriSyncRunStatus::Parsing);

        return $reconciled;
    };
});

afterEach(function (): void {
    $this->context->end();
});

/**
 * Publica el paquete del robot con claves válidas del período.
 *
 * @param  list<string>|null  $keys
 */
function publishPeriod(
    Company $company,
    string $period,
    SriDocumentType $type = SriDocumentType::Invoice,
    ?array $keys = null,
    ?string $txt = null,
): void {
    $keys ??= [
        AccessKeyFactory::make(date: '15092026', type: $type->value, sequential: '000000001'),
        AccessKeyFactory::make(date: '15092026', type: $type->value, sequential: '000000002'),
    ];
    $txt ??= implode("\n", $keys)."\n";

    ReconcileFixtures::putPeriod($company, $period, $type, $txt, $keys);
}

it('interpreta el TXT de un run en parsing y lo deja listo para importar', function (): void {
    publishPeriod($this->company, '2026-09');
    $run = ($this->parsedRun)();

    $result = $this->action->handle($run);

    expect($result['run']->status)->toBe(SriSyncRunStatus::Parsing)
        ->and($result['batches'])->toHaveCount(1)
        ->and($result['batches'][0]->count())->toBe(2)
        ->and($result['run']->totals['found'])->toBe(2)
        ->and($result['run']->error_code)->toBeNull();

    $txt = $result['run']->files()
        ->where('document_type', SriDocumentType::Invoice->value)
        ->where('s3_key', 'like', '%.txt')
        ->firstOrFail();

    expect($txt->status)->toBe(SriSyncRunFileStatus::Processed)
        ->and($txt->processed_at)->not->toBeNull();
});

it('primero reconcilia un run en downloading y luego lo interpreta', function (): void {
    publishPeriod($this->company, '2026-09');
    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Downloading);

    $result = $this->action->handle($run);

    expect($result['run']->status)->toBe(SriSyncRunStatus::Parsing)
        ->and($result['batches'])->toHaveCount(1);
});

it('deja el run en partial cuando falta un tipo solicitado', function (): void {
    publishPeriod($this->company, '2026-09', SriDocumentType::Invoice);

    $run = ($this->parsedRun)(['01', '04']);

    $result = $this->action->handle($run);

    expect($result['run']->status)->toBe(SriSyncRunStatus::Parsing)
        ->and($result['run']->error_code)->toBe('incomplete_package')
        ->and($result['batches'])->toHaveCount(1);
});

it('deja el run en partial cuando el TXT y el JSON de claves no coinciden', function (): void {
    $declared = [AccessKeyFactory::make(sequential: '000000001')];
    $listed = [AccessKeyFactory::make(sequential: '000000001'), AccessKeyFactory::make(sequential: '000000002')];

    ReconcileFixtures::putPeriod(
        $this->company,
        '2026-09',
        txt: $declared[0]."\n",
        keys: $listed,
    );

    $run = ($this->parsedRun)();
    $result = $this->action->handle($run);

    expect($result['run']->status)->toBe(SriSyncRunStatus::Parsing)
        ->and($result['run']->error_code)->toBe('incomplete_package')
        ->and($result['batches'][0]->missingAccessKeys)->toBe([$listed[1]]);
});

it('falla el run cuando ningún archivo tiene claves válidas', function (): void {
    ReconcileFixtures::putPeriod($this->company, '2026-09', txt: "basura\n", keys: []);
    $run = ($this->parsedRun)();

    $result = $this->action->handle($run);

    expect($result['run']->status)->toBe(SriSyncRunStatus::Failed)
        ->and($result['run']->error_code)->toBe('no_access_keys')
        ->and($result['batches'])->toBe([]);
});

it('no procesa un run que ya está final', function (): void {
    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Completed);

    $result = $this->action->handle($run);

    expect($result['run']->status)->toBe(SriSyncRunStatus::Completed)
        ->and($result['batches'])->toBe([])
        ->and($result['run']->files()->count())->toBe(0);
});

it('no procesa un run que aún espera al robot', function (): void {
    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Queued);

    $result = $this->action->handle($run);

    expect($result['run']->status)->toBe(SriSyncRunStatus::Queued)
        ->and($result['batches'])->toBe([]);
});

it('es idempotente al repetir la interpretación', function (): void {
    publishPeriod($this->company, '2026-09');
    $run = ($this->parsedRun)();

    $this->action->handle($run);
    $second = $this->action->handle(SriSyncRun::query()->findOrFail($run->id));

    expect($second['run']->status)->toBe(SriSyncRunStatus::Parsing)
        ->and($second['batches'])->toHaveCount(1)
        ->and($second['run']->files()->where('status', SriSyncRunFileStatus::Processed->value)->count())->toBe(1);
});

it('se ejecuta desde el comando para un run puntual', function (): void {
    publishPeriod($this->company, '2026-09');
    $run = ($this->parsedRun)();

    $this->artisan('sri:parse-run', ['run' => $run->public_id])
        ->expectsOutputToContain('parsing')
        ->assertSuccessful();

    expect($run->refresh()->status)->toBe(SriSyncRunStatus::Parsing);
});

it('falla el comando con un run inexistente', function (): void {
    $this->artisan('sri:parse-run', ['run' => '01ARZ3NDEKTSV4RRFFQ69G5FAV'])
        ->assertFailed();
});

it('se niega a procesar un run que no está listo', function (): void {
    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Invoking);

    $this->artisan('sri:parse-run', ['run' => (string) $run->id])
        ->assertFailed();

    expect($run->refresh()->status)->toBe(SriSyncRunStatus::Invoking);
});
