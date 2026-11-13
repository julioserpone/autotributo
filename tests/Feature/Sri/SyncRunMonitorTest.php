<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'monitor']);
    app(TenantContext::class)->initialize($this->tenant);

    $makeUser = function (MembershipRole $role): User {
        app(TenantContext::class)->initialize($this->tenant);

        $user = User::factory()->create();

        $user->assignRole(Role::firstOrCreate([
            'name' => $role->value,
            'guard_name' => 'web',
            'tenant_id' => $this->tenant->id,
        ]));

        return $user;
    };

    $this->viewer = $makeUser(MembershipRole::Viewer);
    $this->owner = $makeUser(MembershipRole::Owner);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '0990000018001',
    ]);
});

function makeSyncRun(Tenant $tenant, Company $company, array $overrides = []): SriSyncRun
{
    $status = $overrides['status'] ?? SriSyncRunStatus::Downloading;

    return SriSyncRun::query()->create(array_merge([
        'tenant_id' => $tenant->id,
        'company_id' => $company->id,
        'status' => $status,
        'date_from' => '2026-10-01',
        'date_to' => '2026-10-31',
        'periods' => ['2026-10'],
        'document_types' => ['01'],
        'idempotency_key' => 'monitor-'.uniqid(),
        'totals' => ['found' => 10, 'imported' => 2, 'skipped' => 1, 'failed' => 0],
    ], $overrides));
}

it('lista solo los runs del tenant con filtros y paginación', function (): void {
    $active = makeSyncRun($this->tenant, $this->company);
    $finished = makeSyncRun($this->tenant, $this->company, [
        'status' => SriSyncRunStatus::Completed,
        'finished_at' => Carbon::parse('2026-11-01 10:00:00'),
    ]);

    $otherTenant = Tenant::factory()->create(['slug' => 'monitor-otro']);
    app(TenantContext::class)->initialize($otherTenant);
    $otherCompany = Company::factory()->create([
        'tenant_id' => $otherTenant->id,
        'ruc' => '0990000019001',
    ]);
    makeSyncRun($otherTenant, $otherCompany);

    app(TenantContext::class)->initialize($this->tenant);

    $response = $this->actingAs($this->viewer)
        ->getJson('/api/v1/sync-runs?per_page=10');

    $response->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.per_page', 10);

    $ids = array_column($response->json('data'), 'id');

    expect($ids)->toContain($active->public_id, $finished->public_id);

    $filtered = $this->actingAs($this->viewer)
        ->getJson('/api/v1/sync-runs?status=completed');

    $filtered->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $finished->public_id);

    $byCompany = $this->actingAs($this->viewer)
        ->getJson('/api/v1/sync-runs?company_id='.$this->company->public_id);

    $byCompany->assertOk()->assertJsonCount(2, 'data');
});

it('muestra el estado del run con el intervalo de polling mientras sigue activo', function (): void {
    $run = makeSyncRun($this->tenant, $this->company);

    $response = $this->actingAs($this->viewer)
        ->getJson('/api/v1/sync-runs/'.$run->public_id);

    $response->assertOk()
        ->assertJsonPath('data.id', $run->public_id)
        ->assertJsonPath('data.company_id', $this->company->public_id)
        ->assertJsonPath('data.status', 'downloading')
        ->assertJsonPath('data.is_active', true)
        ->assertJsonPath('data.poll_after_ms', 5000)
        ->assertJsonPath('data.progress', 30);
});

it('deja de indicar polling cuando el run es final', function (): void {
    $run = makeSyncRun($this->tenant, $this->company, [
        'status' => SriSyncRunStatus::Completed,
        'finished_at' => now(),
    ]);

    $this->actingAs($this->viewer)
        ->getJson('/api/v1/sync-runs/'.$run->public_id)
        ->assertOk()
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.poll_after_ms', null);
});

it('lista los archivos del run para el monitor', function (): void {
    $run = makeSyncRun($this->tenant, $this->company);

    $run->files()->create([
        'tenant_id' => $this->tenant->id,
        'file_name' => 'factura.xml',
        's3_key' => 'tenant-1/run/factura.xml',
        'status' => 'processed',
    ]);

    $this->actingAs($this->viewer)
        ->getJson('/api/v1/sync-runs/'.$run->public_id.'/files')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.file_name', 'factura.xml')
        ->assertJsonPath('data.0.status', 'processed');
});

it('no expone runs de otro tenant', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'monitor-ajeno']);
    app(TenantContext::class)->initialize($otherTenant);

    $otherCompany = Company::factory()->create([
        'tenant_id' => $otherTenant->id,
        'ruc' => '0990000020001',
    ]);
    $foreignRun = makeSyncRun($otherTenant, $otherCompany);

    app(TenantContext::class)->initialize($this->tenant);

    $this->actingAs($this->owner)
        ->getJson('/api/v1/sync-runs/'.$foreignRun->public_id)
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->getJson('/api/v1/sync-runs/'.$foreignRun->public_id.'/files')
        ->assertNotFound();

    $this->actingAs($this->owner)
        ->getJson('/api/v1/sync-runs')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('permite consultar al viewer pero no iniciar sincronizaciones', function (): void {
    $run = makeSyncRun($this->tenant, $this->company);

    $this->actingAs($this->viewer)
        ->getJson('/api/v1/sync-runs/'.$run->public_id)
        ->assertOk();

    $this->actingAs($this->viewer)
        ->postJson('/api/v1/sync-runs', ['company_id' => $this->company->public_id], [
            'Idempotency-Key' => 'viewer-no-puede',
        ])
        ->assertForbidden();
});

it('exige la clave de idempotencia al iniciar una sincronización', function (): void {
    $this->actingAs($this->owner)
        ->postJson('/api/v1/sync-runs', [
            'company_id' => $this->company->public_id,
            'periods' => ['2026-10'],
        ])
        ->assertForbidden();
});
