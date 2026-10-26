<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriRobotCallback;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Testing\TestResponse;

uses(RefreshDatabase::class);

const SRI_CALLBACK_SECRET = 'secreto-compartido-de-prueba';

beforeEach(function (): void {
    config([
        'sri.robot.callback_secret' => SRI_CALLBACK_SECRET,
        'sri.robot.callback_tolerance_seconds' => 300,
        'sri.robot.callback_max_bytes' => 262_144,
    ]);

    $this->tenant = Tenant::factory()->create();
    $this->context = app(TenantContext::class);
    $this->context->initialize($this->tenant);
    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '1790012345001',
    ]);

    $this->run = SriSyncRun::create([
        'company_id' => $this->company->id,
        'status' => SriSyncRunStatus::Queued,
        'date_from' => '2026-08-01',
        'date_to' => '2026-08-31',
        'periods' => ['2026-08'],
        'document_types' => ['01'],
        'idempotency_key' => 'idem-callback',
        'totals' => ['found' => 0, 'imported' => 0, 'skipped' => 0, 'failed' => 0],
    ]);
    $this->run->markInvoking('req-1', 'evt-1');
});

afterEach(function (): void {
    $this->context->end();
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function sriCallbackBody(array $overrides = [], ?int $timestamp = null): array
{
    return array_merge([
        'schema_version' => 1,
        'event_id' => 'evt-1',
        'event_type' => 'sri.robot.finished',
        'timestamp' => $timestamp ?? time(),
        'status' => 'completed',
        'ambiente' => 'produccion',
        'ruc' => '1790012345001',
        'periodos' => ['2026-08'],
        'request_id' => 'req-1',
        'started_at' => '2026-10-26T18:00:00+00:00',
        'finished_at' => '2026-10-26T18:01:00+00:00',
        'duration_ms' => 60_000,
        'result' => [
            'descargados' => [],
            'omitidos' => [],
            'sinDatos' => [],
            'fallos' => [],
            'totalClaves' => 0,
        ],
        'error' => null,
    ], $overrides);
}

/**
 * @param  array<string, mixed>  $body
 */
function enviarCallbackSri(
    $test,
    array $body,
    string $secret = SRI_CALLBACK_SECRET,
    ?int $firmaTimestamp = null,
    ?string $eventIdCabecera = null,
): TestResponse {
    $json = json_encode($body, JSON_THROW_ON_ERROR);
    $momento = $firmaTimestamp ?? $body['timestamp'];
    $firma = 'sha256='.hash_hmac('sha256', $momento.'.'.$json, $secret);

    return $test->call('POST', '/api/v1/webhooks/sri-robot', [], [], [], [
        'CONTENT_TYPE' => 'application/json',
        'HTTP_ACCEPT' => 'application/json',
        'HTTP_X_SRI_EVENT_ID' => $eventIdCabecera ?? $body['event_id'],
        'HTTP_X_SRI_TIMESTAMP' => (string) $momento,
        'HTTP_X_SRI_SIGNATURE' => $firma,
    ], $json);
}

it('acepta un callback firmado y deja el run listo para reconciliar', function (): void {
    $response = enviarCallbackSri($this, sriCallbackBody());

    $response->assertStatus(202)->assertJson(['status' => 'accepted']);

    $evento = SriRobotCallback::query()->firstOrFail();

    expect($evento->event_id)->toBe('evt-1')
        ->and($evento->run_id)->toBe($this->run->id)
        ->and($evento->status)->toBe('completed')
        ->and($evento->payload)->toHaveKey('schema_version')
        ->and($this->run->refresh()->status)->toBe(SriSyncRunStatus::Downloading);
});

it('rechaza una firma inválida sin guardar el evento', function (): void {
    $response = enviarCallbackSri($this, sriCallbackBody(), 'otro-secreto-distinto');

    $response->assertStatus(401);

    expect(SriRobotCallback::query()->count())->toBe(0)
        ->and($this->run->refresh()->status)->toBe(SriSyncRunStatus::Invoking);
});

it('rechaza un callback fuera de la ventana temporal', function (): void {
    $viejo = time() - 900;

    $response = enviarCallbackSri($this, sriCallbackBody(timestamp: $viejo), firmaTimestamp: $viejo);

    $response->assertStatus(401);
    expect(SriRobotCallback::query()->count())->toBe(0);
});

it('deduplica por event_id y responde 202 sin reprocesar', function (): void {
    enviarCallbackSri($this, sriCallbackBody())->assertStatus(202);
    enviarCallbackSri($this, sriCallbackBody())->assertStatus(202);

    expect(SriRobotCallback::query()->count())->toBe(1)
        ->and($this->run->refresh()->status)->toBe(SriSyncRunStatus::Downloading);
});

it('marca el run como fallido cuando el robot informa failed', function (): void {
    $response = enviarCallbackSri($this, sriCallbackBody([
        'status' => 'failed',
        'error' => 'RuntimeError: el portal no respondió',
    ]));

    $response->assertStatus(202);

    $run = $this->run->refresh();

    expect($run->status)->toBe(SriSyncRunStatus::Failed)
        ->and($run->error_code)->toBe('robot_failed');
});

it('acepta callbacks de eventos que no pertenecen a un run local', function (): void {
    $response = enviarCallbackSri($this, sriCallbackBody(['event_id' => 'sri-desconocido']));

    $response->assertStatus(202);

    expect(SriRobotCallback::query()->firstOrFail()->run_id)->toBeNull();
});

it('rechaza un cuerpo demasiado grande', function (): void {
    config(['sri.robot.callback_max_bytes' => 256]);

    $response = enviarCallbackSri($this, sriCallbackBody(['ruc' => str_repeat('9', 400)]));

    $response->assertStatus(413);
    expect(SriRobotCallback::query()->count())->toBe(0);
});

it('rechaza un cuerpo cuyo event_id no coincide con la cabecera', function (): void {
    $response = enviarCallbackSri(
        $this,
        sriCallbackBody(['event_id' => 'evt-otro']),
        eventIdCabecera: 'evt-1',
    );

    $response->assertStatus(422);
});

it('responde 503 si el secreto no está configurado', function (): void {
    config(['sri.robot.callback_secret' => '']);

    enviarCallbackSri($this, sriCallbackBody())->assertStatus(503);
});
