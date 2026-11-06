<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Actions\FinalizeSriRun;
use App\Domain\Sri\Actions\SriRunMetrics;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriImportOutcome;
use App\Domain\Sri\Enums\SriSyncRunFileStatus;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Sri\Models\SriSyncRunFile;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Tests\Support\Sri\ReconcileFixtures;

uses(RefreshDatabase::class);

beforeEach(function (): void {
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

function metricsXmlRow(SriSyncRun $run, string $name, SriImportOutcome $outcome, ?string $source = null, SriSyncRunFileStatus $status = SriSyncRunFileStatus::Processed, ?string $errorCode = null): SriSyncRunFile
{
    return $run->files()->create([
        'file_name' => $name.'.xml',
        's3_key' => '2026-09/01-recibidas-2026-09-xml/'.$name.'.xml',
        'document_type' => SriDocumentType::Invoice,
        'status' => $status,
        'import_outcome' => $outcome,
        'source' => $source,
        'error_code' => $errorCode,
        'processed_at' => now(),
    ]);
}

it('calcula duración y volumen a partir de las filas persistidas', function (): void {
    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Parsing);
    $run->started_at = now()->subSeconds(12);
    $run->finished_at = now();
    $run->totals = [
        'found' => 2,
        'imported' => 1,
        'updated' => 1,
        'skipped' => 0,
        'failed' => 0,
        'from_s3' => 1,
        'from_sri' => 1,
    ];
    $run->save();

    metricsXmlRow($run, 'a', SriImportOutcome::Created, 's3');
    metricsXmlRow($run, 'b', SriImportOutcome::Updated, 'sri');

    $metrics = app(SriRunMetrics::class)->handle($run->refresh());

    expect($metrics['run_id'])->toBe($run->id)
        ->and($metrics['tenant_id'])->toBe($this->tenant->id)
        ->and($metrics['company_id'])->toBe($this->company->id)
        ->and($metrics['duration_ms'])->toBeGreaterThanOrEqual(11000)
        ->and($metrics['documents']['total'])->toBe(2)
        ->and($metrics['documents']['processed'])->toBe(2)
        ->and($metrics['documents']['from_s3'])->toBe(1)
        ->and($metrics['documents']['from_sri'])->toBe(1)
        ->and($metrics['totals']['found'])->toBe(2)
        ->and($metrics['totals']['imported'])->toBe(1);
});

it('registra una línea informativa cuando el run se completa', function (): void {
    Log::spy();

    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Parsing);
    metricsXmlRow($run, 'a', SriImportOutcome::Created, 's3');

    app(FinalizeSriRun::class)->handle($run);

    Log::shouldHaveReceived('info')
        ->withArgs(static function (string $message, array $context) use ($run): bool {
            return $message === 'sri.run.finished'
                && $context['status'] === 'completed'
                && $context['run_id'] === $run->id;
        });
});

it('registra una advertencia cuando el run queda parcial', function (): void {
    Log::spy();

    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Parsing);
    $run->totals = ['found' => 2, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
    $run->save();

    metricsXmlRow($run, 'a', SriImportOutcome::Created, 's3');
    metricsXmlRow($run, 'b', SriImportOutcome::Created, null, SriSyncRunFileStatus::Missing, 'sri_unavailable');

    app(FinalizeSriRun::class)->handle($run);

    Log::shouldHaveReceived('warning')
        ->withArgs(static fn (string $message, array $context): bool => $message === 'sri.run.partial');
});

it('registra un error cuando el run falla', function (): void {
    Log::spy();

    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Parsing);
    $run->totals = ['found' => 1, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
    $run->save();

    app(FinalizeSriRun::class)->handle($run);

    Log::shouldHaveReceived('error')
        ->withArgs(static fn (string $message, array $context): bool => $message === 'sri.run.failed');
});

it('no duplica la métrica al guardar un run ya cerrado', function (): void {
    Log::spy();

    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Parsing);
    metricsXmlRow($run, 'a', SriImportOutcome::Created, 's3');

    app(FinalizeSriRun::class)->handle($run);

    // Guardar de nuevo no debe disparar otra línea de métricas.
    $run->refresh();
    $run->save();

    Log::shouldHaveReceived('info')
        ->withArgs(static fn (string $message, array $context): bool => $message === 'sri.run.finished')
        ->once();
});
