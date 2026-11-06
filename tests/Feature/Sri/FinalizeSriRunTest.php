<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Actions\FinalizeSriRun;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriImportOutcome;
use App\Domain\Sri\Enums\SriSyncRunFileStatus;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Jobs\FinalizeSriRunJob;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Sri\Models\SriSyncRunFile;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

function finalizeRun(Company $company): SriSyncRun
{
    return ReconcileFixtures::run($company, status: SriSyncRunStatus::Parsing);
}

function addXmlRow(
    SriSyncRun $run,
    string $name,
    ?SriImportOutcome $outcome = null,
    ?string $errorCode = null,
    ?string $source = null,
    SriSyncRunFileStatus $status = SriSyncRunFileStatus::Processed,
): SriSyncRunFile {
    return $run->files()->create([
        'file_name' => $name.'.xml',
        's3_key' => '2026-09/01-recibidas-2026-09-xml/'.$name.'.xml',
        'document_type' => SriDocumentType::Invoice,
        'status' => $status,
        'error_code' => $errorCode,
        'import_outcome' => $outcome,
        'source' => $source,
        'processed_at' => now(),
    ]);
}

it('cierra el run en partial cuando algunos comprobantes fallan', function (): void {
    $run = finalizeRun($this->company);

    addXmlRow($run, 'a', SriImportOutcome::Created, source: 's3');
    addXmlRow($run, 'b', SriImportOutcome::Updated, source: 'sri');
    addXmlRow($run, 'c', SriImportOutcome::Skipped, source: 's3');
    addXmlRow($run, 'd', errorCode: 'invalid_xml', source: null, status: SriSyncRunFileStatus::Invalid);

    $result = app(FinalizeSriRun::class)->handle($run);

    expect($result->status)->toBe(SriSyncRunStatus::Partial)
        ->and($result->error_code)->toBe('partial_import')
        ->and($result->error_message)->toContain('1 comprobante')
        ->and($result->totals)->toMatchArray([
            'found' => 4,
            'imported' => 1,
            'updated' => 1,
            'skipped' => 1,
            'failed' => 1,
            'from_s3' => 2,
            'from_sri' => 1,
        ])
        ->and($result->finished_at)->not->toBeNull();
});

it('cierra el run en failed cuando ningún comprobante se guardó', function (): void {
    $run = finalizeRun($this->company);

    addXmlRow($run, 'a', errorCode: 'sri_unavailable', status: SriSyncRunFileStatus::Missing);
    addXmlRow($run, 'b', errorCode: 'invalid_xml', status: SriSyncRunFileStatus::Invalid);

    $result = app(FinalizeSriRun::class)->handle($run);

    expect($result->status)->toBe(SriSyncRunStatus::Failed)
        ->and($result->error_code)->toBe('import_failed')
        ->and($result->totals['failed'])->toBe(2);
});

it('cierra el run en completed y limpia errores previos', function (): void {
    $run = finalizeRun($this->company);
    $run->error_code = 'incomplete_package';
    $run->error_message = 'El paquete del robot tiene archivos vacíos.';
    $run->save();

    addXmlRow($run, 'a', SriImportOutcome::Created, source: 's3');
    addXmlRow($run, 'b', SriImportOutcome::Skipped, source: 's3');

    $result = app(FinalizeSriRun::class)->handle($run);

    expect($result->status)->toBe(SriSyncRunStatus::Completed)
        ->and($result->error_code)->toBeNull()
        ->and($result->error_message)->toBeNull()
        ->and($result->totals['found'])->toBe(2);
});

it('cierra el run en partial si faltan comprobantes esperados', function (): void {
    $run = finalizeRun($this->company);
    $run->totals = ['found' => 3, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 0];
    $run->save();

    addXmlRow($run, 'a', SriImportOutcome::Created, source: 's3');

    $result = app(FinalizeSriRun::class)->handle($run);

    expect($result->status)->toBe(SriSyncRunStatus::Partial)
        ->and($result->error_code)->toBe('partial_import')
        ->and($result->totals['failed'])->toBe(2)
        ->and($result->error_message)->toContain('2 comprobante');
});

it('es idempotente al cerrar el mismo run dos veces', function (): void {
    $run = finalizeRun($this->company);
    addXmlRow($run, 'a', SriImportOutcome::Created, source: 's3');

    $first = app(FinalizeSriRun::class)->handle($run);
    $finishedAt = $first->finished_at?->toIso8601String();
    $second = app(FinalizeSriRun::class)->handle($first);

    expect($second->status)->toBe(SriSyncRunStatus::Completed)
        ->and($second->totals)->toBe($first->totals)
        ->and($second->finished_at?->toIso8601String())->toBe($finishedAt);
});

it('no reabre un run que ya está en estado final', function (): void {
    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Completed);

    $result = app(FinalizeSriRun::class)->handle($run);

    expect($result->status)->toBe(SriSyncRunStatus::Completed)
        ->and($result->totals)->toBe($run->totals);
});

it('el job de cierre finaliza el run y es idempotente', function (): void {
    $run = finalizeRun($this->company);
    addXmlRow($run, 'a', SriImportOutcome::Created, source: 's3');

    (new FinalizeSriRunJob($run->id))->handle();
    (new FinalizeSriRunJob($run->id))->handle();

    expect($run->refresh()->status)->toBe(SriSyncRunStatus::Completed)
        ->and($run->totals['imported'])->toBe(1);
});

it('el job de cierre marca failed si agota los reintentos en parsing', function (): void {
    $run = finalizeRun($this->company);

    (new FinalizeSriRunJob($run->id))->failed(new RuntimeException('fallo simulado'));

    expect($run->refresh()->status)->toBe(SriSyncRunStatus::Failed)
        ->and($run->error_code)->toBe('import_failed');
});
