<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Sri\Actions\RecoverSriRunFromS3;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Jobs\ImportSriChunkJob;
use App\Domain\Sri\Jobs\ProcessSriRunJob;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Bus\PendingBatch;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Support\Sri\AccessKeyFactory;
use Tests\Support\Sri\ReconcileFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('sri');
    config(['sri.robot.environment' => 'dev']);

    // Claves emitidas en septiembre; el XML solo puede venir de S3.
    $this->travelTo(CarbonImmutable::parse('2026-12-01 12:00:00'));

    $this->tenant = Tenant::factory()->create();
    $this->context = app(TenantContext::class);
    $this->context->initialize($this->tenant);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '1758871873001',
    ]);

    $this->key = AccessKeyFactory::make(sequential: '000000001');
});

afterEach(function (): void {
    $this->context->end();
});

function runReadyToImport(Company $company, string $key): SriSyncRun
{
    $run = ReconcileFixtures::run($company);

    ReconcileFixtures::putPeriod(
        $company,
        '2026-09',
        SriDocumentType::Invoice,
        $key."\n",
        [$key],
    );

    $template = (string) file_get_contents(__DIR__.'/../../Fixtures/xml/invoice/factura.xml');
    ReconcileFixtures::putXml($company, '2026-09', $key, str_replace('{{CLAVE}}', $key, $template));

    return app(RecoverSriRunFromS3::class)->handle($run);
}

it('importa en cola un run en parsing', function (): void {
    $run = runReadyToImport($this->company, $this->key);

    (new ProcessSriRunJob($run->id))->handle();

    expect($run->refresh()->status)->toBe(SriSyncRunStatus::Completed)
        ->and($run->totals['imported'])->toBe(1)
        ->and($run->error_code)->toBeNull()
        ->and(Invoice::query()->count())->toBe(1);

    // El finalizador libera el bloqueo del batch aunque lo haya tomado otro
    // proceso: sin esto, un run posterior quedaría bloqueado durante 1 hora.
    $lock = Cache::lock(ProcessSriRunJob::lockKeyFor($run->id), 60);

    expect($lock->get())->toBeTrue();

    $lock->release();
});

it('omite un run que ya no está en parsing', function (): void {
    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Completed);

    (new ProcessSriRunJob($run->id))->handle();

    expect($run->refresh()->status)->toBe(SriSyncRunStatus::Completed)
        ->and(Invoice::query()->count())->toBe(0);
});

it('reparte las claves en lotes según chunk_size', function (): void {
    Bus::fake();
    config(['sri.sync.chunk_size' => 2]);

    $keys = [
        AccessKeyFactory::make(sequential: '000000001'),
        AccessKeyFactory::make(sequential: '000000002'),
        AccessKeyFactory::make(sequential: '000000003'),
    ];

    $run = ReconcileFixtures::run($this->company);
    ReconcileFixtures::putPeriod(
        $this->company,
        '2026-09',
        SriDocumentType::Invoice,
        implode("\n", $keys)."\n",
        $keys,
    );

    $run = app(RecoverSriRunFromS3::class)->handle($run);

    (new ProcessSriRunJob($run->id))->handle();

    Bus::assertBatched(function (PendingBatch $batch) use ($run, $keys): bool {
        $jobs = $batch->jobs;

        return $jobs->count() === 2
            && $jobs->every(
                static fn ($job): bool => $job instanceof ImportSriChunkJob
                    && $job->runId === $run->id
                    && $job->documentType === '01'
                    && $job->period === '2026-09',
            )
            && $jobs->flatMap(static fn (ImportSriChunkJob $job): array => $job->keys)->values()->all() === $keys;
    });
});

it('no encola un segundo batch mientras el run sigue en parsing', function (): void {
    Bus::fake();
    $run = runReadyToImport($this->company, $this->key);

    (new ProcessSriRunJob($run->id))->handle();
    (new ProcessSriRunJob($run->id))->handle();

    Bus::assertBatchCount(1);
});

it('importa un run de varios lotes de extremo a extremo', function (): void {
    config(['sri.sync.chunk_size' => 2]);

    $keys = [
        AccessKeyFactory::make(sequential: '000000001'),
        AccessKeyFactory::make(sequential: '000000002'),
        AccessKeyFactory::make(sequential: '000000003'),
    ];

    $run = ReconcileFixtures::run($this->company);
    ReconcileFixtures::putPeriod(
        $this->company,
        '2026-09',
        SriDocumentType::Invoice,
        implode("\n", $keys)."\n",
        $keys,
    );

    $template = (string) file_get_contents(__DIR__.'/../../Fixtures/xml/invoice/factura.xml');

    foreach ($keys as $key) {
        ReconcileFixtures::putXml($this->company, '2026-09', $key, str_replace('{{CLAVE}}', $key, $template));
    }

    $run = app(RecoverSriRunFromS3::class)->handle($run);

    (new ProcessSriRunJob($run->id))->handle();

    expect($run->refresh()->status)->toBe(SriSyncRunStatus::Completed)
        ->and($run->totals['imported'])->toBe(3)
        ->and(Invoice::query()->count())->toBe(3)
        ->and($run->files()->where('s3_key', 'like', '%-xml/%')->where('status', 'processed')->count())->toBe(3);
});

it('no falla si el run ya no existe', function (): void {
    (new ProcessSriRunJob(999_999))->handle();

    expect(Invoice::query()->count())->toBe(0);
});

it('usa el id del run como clave de unicidad', function (): void {
    $job = new ProcessSriRunJob(42);

    expect($job->uniqueId())->toBe('42')
        ->and($job->queue)->toBe('sri')
        ->and($job->tries)->toBe(3)
        ->and($job->backoff)->toBe([30, 120, 300]);
});

it('cierra el run como fallido cuando se agotan los reintentos', function (): void {
    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Parsing);

    (new ProcessSriRunJob($run->id))->failed(new RuntimeException('fallo simulado'));

    expect($run->refresh()->status)->toBe(SriSyncRunStatus::Failed)
        ->and($run->error_code)->toBe('import_failed');
});
