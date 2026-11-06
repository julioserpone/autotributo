<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;

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

it('agrega runs de la ventana y calcula duración y tasa de fallo', function (): void {
    $start = CarbonImmutable::parse('2026-11-01 10:00:00');
    $end = $start->addSeconds(12);

    SriSyncRun::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'status' => SriSyncRunStatus::Completed,
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-30',
        'periods' => ['2026-09'],
        'document_types' => ['01'],
        'idempotency_key' => 'metrics-completed',
        'started_at' => $start,
        'finished_at' => $end,
        'totals' => ['found' => 4, 'imported' => 3, 'updated' => 1, 'skipped' => 0, 'failed' => 0, 'from_s3' => 2, 'from_sri' => 2],
    ]);

    SriSyncRun::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'status' => SriSyncRunStatus::Failed,
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-30',
        'periods' => ['2026-09'],
        'document_types' => ['01'],
        'idempotency_key' => 'metrics-failed',
        'started_at' => $start,
        'finished_at' => $end,
        'totals' => ['found' => 2, 'imported' => 0, 'updated' => 0, 'skipped' => 0, 'failed' => 2, 'from_s3' => 0, 'from_sri' => 0],
    ]);

    Artisan::call('sri:metrics', ['--days' => 7, '--json' => true]);

    $output = (string) Artisan::output();
    $metrics = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

    expect($metrics['runs']['total'])->toBe(2)
        ->and($metrics['runs']['completed'])->toBe(1)
        ->and($metrics['runs']['failed'])->toBe(1)
        ->and($metrics['duration']['samples'])->toBe(2)
        ->and($metrics['duration']['avg_seconds'])->toBe(12)
        ->and($metrics['volume']['found'])->toBe(6)
        ->and($metrics['volume']['imported'])->toBe(3)
        ->and($metrics['failure_rate'])->toBe(0.5);
});

it('devuelve ceros cuando no hay runs en la ventana', function (): void {
    Artisan::call('sri:metrics', ['--days' => 1, '--json' => true]);

    $output = (string) Artisan::output();
    $metrics = json_decode(trim($output), true, flags: JSON_THROW_ON_ERROR);

    expect($metrics['runs']['total'])->toBe(0)
        ->and($metrics['duration']['samples'])->toBe(0)
        ->and($metrics['failure_rate'])->toBe(0);
});
