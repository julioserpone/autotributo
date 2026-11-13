<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Events\SriSyncProgressUpdated;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Http\Resources\SriSyncRunResource;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'realtime-sri']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '0990000018001',
    ]);

    $this->run = SriSyncRun::query()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'status' => SriSyncRunStatus::Downloading,
        'date_from' => '2026-10-01',
        'date_to' => '2026-10-31',
        'periods' => ['2026-10'],
        'document_types' => ['01'],
        'idempotency_key' => 'realtime-'.uniqid(),
        'totals' => ['found' => 8, 'imported' => 2, 'skipped' => 1, 'failed' => 1],
    ]);
});

it('emite en el canal privado de la empresa', function (): void {
    $event = new SriSyncProgressUpdated($this->run);

    expect($event->broadcastOn()[0]->name)
        ->toBe('private-tenant.'.$this->tenant->id.'.company.'.$this->company->public_id)
        ->and($event->broadcastAs())->toBe('SriSyncProgressUpdated');
});

it('resuelve el canal sin contexto de tenant para el webhook del robot', function (): void {
    app(TenantContext::class)->end();

    $event = new SriSyncProgressUpdated($this->run);

    expect($event->broadcastOn()[0]->name)
        ->toBe('private-tenant.'.$this->tenant->id.'.company.'.$this->company->public_id);
});

it('publica el progreso y el intervalo de polling', function (): void {
    $event = new SriSyncProgressUpdated($this->run);
    $payload = $event->broadcastWith();

    expect($payload['run_id'])->toBe($this->run->public_id)
        ->and($payload['status'])->toBe('downloading')
        ->and($payload['is_active'])->toBeTrue()
        ->and($payload['poll_after_ms'])->toBe(SriSyncRunResource::POLL_INTERVAL_MS)
        ->and($payload['progress'])->toBe(50.0)
        ->and($payload['totals'])->toBe(['found' => 8, 'imported' => 2, 'skipped' => 1, 'failed' => 1]);
});

it('no publica polling cuando el run es final', function (): void {
    $this->run->forceFill(['status' => SriSyncRunStatus::Completed])->save();

    $payload = (new SriSyncProgressUpdated($this->run))->broadcastWith();

    expect($payload['is_active'])->toBeFalse()
        ->and($payload['poll_after_ms'])->toBeNull();
});

it('despacha el evento al guardar un run fuera del entorno de pruebas', function (): void {
    $environment = app()->environment();
    app()->instance('env', 'local');
    Event::fake([SriSyncProgressUpdated::class]);

    try {
        $this->run->transitionTo(SriSyncRunStatus::Parsing);
    } finally {
        app()->instance('env', $environment);
    }

    Event::assertDispatched(
        SriSyncProgressUpdated::class,
        fn (SriSyncProgressUpdated $event): bool => $event->run->is($this->run),
    );
});
