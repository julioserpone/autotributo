<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Actions\StartSriSync;
use App\Domain\Sri\Contracts\SriRobotGateway;
use App\Domain\Sri\Data\SriRobotCredentials;
use App\Domain\Sri\Data\SriRobotDispatch;
use App\Domain\Sri\Data\SriRobotRequest;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Integrations\Sri\FakeSriRobotGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create();
    $this->context = app(TenantContext::class);
    $this->context->initialize($this->tenant);
    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '1758871873001',
    ]);
    $this->gateway = new FakeSriRobotGateway;
    $this->action = new StartSriSync($this->gateway);
});

afterEach(function (): void {
    $this->context->end();
});

function sriSyncRequest(array $periods = ['2026-08']): SriRobotRequest
{
    return new SriRobotRequest(
        ruc: '1758871873001',
        periods: $periods,
        documentTypes: ['01', '04'],
        credentials: new SriRobotCredentials('1758871873001', 'clave-real'),
    );
}

it('crea el run y despacha el robot', function (): void {
    $batch = $this->action->handle($this->company, sriSyncRequest(), 'idem-1');

    expect($batch->count())->toBe(1);

    $run = $batch->first();

    expect($run->status)->toBe(SriSyncRunStatus::Invoking)
        ->and($run->company_id)->toBe($this->company->id)
        ->and($run->date_from->toDateString())->toBe('2026-08-01')
        ->and($run->date_to->toDateString())->toBe('2026-08-31')
        ->and($run->periods)->toBe(['2026-08'])
        ->and($run->document_types)->toBe(['01', '04'])
        ->and($run->lambda_request_id)->toBe('fake-1')
        ->and($run->callback_event_id)->toBe('fake-evt-1')
        ->and($run->started_at)->not->toBeNull()
        ->and($run->totals)->toEqual([
            'found' => 0,
            'imported' => 0,
            'skipped' => 0,
            'failed' => 0,
        ])
        ->and($this->gateway->requests)->toHaveCount(1);
});

it('es idempotente por clave y no vuelve a invocar la Lambda', function (): void {
    $first = $this->action->handle($this->company, sriSyncRequest(), 'idem-repetida');
    $second = $this->action->handle($this->company, sriSyncRequest(), 'idem-repetida');

    expect($second->first()->id)->toBe($first->first()->id)
        ->and($this->gateway->requests)->toHaveCount(1)
        ->and(SriSyncRun::query()->count())->toBe(1);
});

it('divide en varios runs cuando el rango supera 93 días', function (): void {
    $periods = ['2026-01', '2026-02', '2026-03', '2026-04'];
    $batch = $this->action->handle($this->company, sriSyncRequest($periods), 'idem-split');

    expect($batch->count())->toBe(2)
        ->and($this->gateway->requests)->toHaveCount(2)
        ->and($batch->runs[0]->date_from->toDateString())->toBe('2026-01-01')
        ->and($batch->runs[0]->date_to->toDateString())->toBe('2026-03-31')
        ->and($batch->runs[1]->date_from->toDateString())->toBe('2026-04-01')
        ->and($batch->runs[1]->date_to->toDateString())->toBe('2026-04-30');

    $again = $this->action->handle($this->company, sriSyncRequest($periods), 'idem-split');

    expect($again->count())->toBe(2)
        ->and($this->gateway->requests)->toHaveCount(2)
        ->and($again->runs[0]->id)->toBe($batch->runs[0]->id)
        ->and($again->runs[1]->id)->toBe($batch->runs[1]->id);
});

it('rechaza una empresa con RUC distinto al de la solicitud', function (): void {
    $other = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '0999999999001',
    ]);

    expect(fn () => $this->action->handle($other, sriSyncRequest(), 'idem-ruc'))
        ->toThrow(InvalidArgumentException::class, 'RUC de la empresa');
});

it('marca el run como fallido si el gateway lanza una excepción', function (): void {
    $failing = new class implements SriRobotGateway
    {
        public function dispatch(SriRobotRequest $request): SriRobotDispatch
        {
            throw new RuntimeException('fallo simulado');
        }
    };

    $action = new StartSriSync($failing);

    expect(fn () => $action->handle($this->company, sriSyncRequest(), 'idem-fallo'))
        ->toThrow(RuntimeException::class, 'fallo simulado');

    $run = SriSyncRun::query()
        ->where('idempotency_key', 'idem-fallo')
        ->firstOrFail();

    expect($run->status)->toBe(SriSyncRunStatus::Failed)
        ->and($run->error_code)->toBe('robot_invocation_failed')
        ->and($run->error_message)->toBe('No fue posible invocar el robot SRI.')
        ->and($run->finished_at)->not->toBeNull();
});

it('cancela un run activo y no reabre un estado final', function (): void {
    $run = $this->action->handle($this->company, sriSyncRequest(), 'idem-cancelar')->first();

    expect($run->cancel())->toBeTrue()
        ->and($run->refresh()->status)->toBe(SriSyncRunStatus::Cancelled)
        ->and($run->finished_at)->not->toBeNull()
        ->and($run->cancel())->toBeFalse();
});
