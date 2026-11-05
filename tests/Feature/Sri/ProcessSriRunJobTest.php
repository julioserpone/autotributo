<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Sri\Actions\RecoverSriRunFromS3;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Jobs\ProcessSriRunJob;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
});

it('omite un run que ya no está en parsing', function (): void {
    $run = ReconcileFixtures::run($this->company, status: SriSyncRunStatus::Completed);

    (new ProcessSriRunJob($run->id))->handle();

    expect($run->refresh()->status)->toBe(SriSyncRunStatus::Completed)
        ->and(Invoice::query()->count())->toBe(0);
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
