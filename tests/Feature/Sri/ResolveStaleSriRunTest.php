<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Actions\FinalizeSriRun;
use App\Domain\Sri\Actions\ReconcileSriRuns;
use App\Domain\Sri\Actions\RecoverSriRunFromS3;
use App\Domain\Sri\Actions\ResolveStaleSriRun;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriImportOutcome;
use App\Domain\Sri\Enums\SriSyncRunFileStatus;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Jobs\ProcessSriRunJob;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Sri\Models\SriSyncRunFile;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Queue;
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
});

afterEach(function (): void {
    $this->context->end();
});

function markRunStale(SriSyncRun $run, int $minutes = 31): SriSyncRun
{
    SriSyncRun::query()
        ->whereKey($run->id)
        ->update(['updated_at' => now()->subMinutes($minutes)]);

    return $run->refresh();
}

function addStaleXmlRow(SriSyncRun $run, string $name, SriImportOutcome $outcome): SriSyncRunFile
{
    return $run->files()->create([
        'file_name' => $name.'.xml',
        's3_key' => '2026-09/01-recibidas-2026-09-xml/'.$name.'.xml',
        'document_type' => SriDocumentType::Invoice,
        'status' => SriSyncRunFileStatus::Processed,
        'import_outcome' => $outcome,
        'source' => 's3',
        'processed_at' => now(),
    ]);
}

it('no toca un run que sigue avanzando', function (): void {
    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Invoking);

    $result = app(ResolveStaleSriRun::class)->handle($run);

    expect($result->status)->toBe(SriSyncRunStatus::Invoking)
        ->and($result->error_code)->toBeNull();
});

it('cierra con robot_timeout un run invoking sin paquete en S3', function (): void {
    $run = markRunStale(
        ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Invoking),
    );

    $result = app(ResolveStaleSriRun::class)->handle($run);

    expect($result->status)->toBe(SriSyncRunStatus::Failed)
        ->and($result->error_code)->toBe('robot_timeout')
        ->and($result->error_message)->toContain('plazo');
});

it('cierra con robot_timeout un run downloading sin paquete completo', function (): void {
    $run = markRunStale(
        ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Downloading),
    );

    $result = app(ResolveStaleSriRun::class)->handle($run);

    expect($result->status)->toBe(SriSyncRunStatus::Failed)
        ->and($result->error_code)->toBe('robot_timeout');
});

it('reanuda un run stale cuando el robot ya publicó el paquete', function (): void {
    $key = AccessKeyFactory::make(sequential: '000000001');

    ReconcileFixtures::putPeriod(
        $this->company,
        '2026-09',
        SriDocumentType::Invoice,
        $key."\n",
        [$key],
    );
    ReconcileFixtures::putXml($this->company, '2026-09', $key, '<factura/>');

    $run = markRunStale(
        ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Invoking),
    );

    $result = app(ResolveStaleSriRun::class)->handle($run);

    expect($result->status)->toBe(SriSyncRunStatus::Parsing)
        ->and($result->error_code)->toBeNull();
});

it('cierra un run parsing atascado con las filas persistidas', function (): void {
    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Parsing);
    addStaleXmlRow($run, 'a', SriImportOutcome::Created);
    $run = markRunStale($run);

    $result = app(ResolveStaleSriRun::class)->handle($run);

    expect($result->status)->toBe(SriSyncRunStatus::Completed)
        ->and($result->totals['imported'])->toBe(1);
});

it('cierra con import_timeout un run parsing sin comprobantes persistidos', function (): void {
    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Parsing);
    $run->totals = ['found' => 2, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
    $run->save();
    $run = markRunStale($run);

    $result = app(ResolveStaleSriRun::class)->handle($run);

    expect($result->status)->toBe(SriSyncRunStatus::Failed)
        ->and($result->error_code)->toBe('import_timeout')
        ->and($result->error_message)->toContain('plazo');
});

it('no finaliza un run parsing mientras el batch tiene el bloqueo', function (): void {
    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Parsing);
    $run = markRunStale($run);

    $lock = Cache::lock(ProcessSriRunJob::lockKeyFor($run->id), 60);

    expect($lock->get())->toBeTrue();

    try {
        $result = app(ResolveStaleSriRun::class)->handle($run);

        expect($result->status)->toBe(SriSyncRunStatus::Parsing);
    } finally {
        $lock->release();
    }
});

it('rechaza un plazo de huérfano inválido', function (): void {
    new ResolveStaleSriRun(
        app(RecoverSriRunFromS3::class),
        app(FinalizeSriRun::class),
        0,
    );
})->throws(InvalidArgumentException::class);

it('la reconciliación periódica expira los runs huérfanos', function (): void {
    Queue::fake();

    $run = markRunStale(
        ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Invoking),
    );

    $result = app(ReconcileSriRuns::class)->handle(10);

    expect($result)->toHaveCount(1)
        ->and($result[0]->status)->toBe(SriSyncRunStatus::Failed)
        ->and($result[0]->error_code)->toBe('robot_timeout')
        ->and($run->refresh()->status)->toBe(SriSyncRunStatus::Failed);

    Queue::assertNothingPushed();
});

it('alerta cuando hay más de tres runs huérfanos en una reconciliación', function (): void {
    Queue::fake();
    Log::spy();

    foreach (range(1, 4) as $index) {
        markRunStale(
            ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Invoking),
        );
    }

    app(ReconcileSriRuns::class)->handle(10);

    Log::shouldHaveReceived('warning')
        ->withArgs(static fn (string $message, array $context): bool => $message === 'varios runs SRI huérfanos en la última reconciliación'
            && $context['count'] === 4);
});
