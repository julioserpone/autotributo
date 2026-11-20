<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Models\FiscalFinding;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'test']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->owner = User::factory()->create();
    $role = Role::firstOrCreate([
        'name' => MembershipRole::Owner->value,
        'guard_name' => 'web',
        'tenant_id' => $this->tenant->id,
    ]);
    $this->owner->assignRole($role);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '0990000018001',
    ]);

    $this->finding = FiscalFinding::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'code' => 'TOTALS_MISMATCH',
        'severity' => 'critical',
        'status' => 'open',
        'source_type' => 'fiscal_document',
        'source_id' => 1,
    ]);
});

it('lista hallazgos con filtros', function (): void {
    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/fiscal-findings?filter[company_id]='.$this->company->id.'&filter[code]=TOTALS_MISMATCH');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', 'TOTALS_MISMATCH');
});

it('actualiza el estado de un hallazgo y audita', function (): void {
    $response = $this->actingAs($this->owner)
        ->patchJson('/api/v1/fiscal-findings/'.$this->finding->id, [
            'status' => 'resolved',
            'note' => 'Revisado',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'resolved');

    $this->finding->refresh();
    expect($this->finding->status->value)->toBe('resolved')
        ->and($this->finding->metadata['note'] ?? null)->toBe('Revisado');
});

it('dispara la detección manual', function (): void {
    $response = $this->actingAs($this->owner)
        ->postJson('/api/v1/companies/'.$this->company->public_id.'/fiscal-findings/detect');

    $response->assertOk()
        ->assertJsonStructure(['data' => ['created', 'updated', 'resolved', 'total']]);
});

it('devuelve 404 al actualizar un hallazgo de otro tenant', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'other']);

    app(TenantContext::class)->run($otherTenant, function () use ($otherTenant, &$otherCompany, &$otherFinding): void {
        $otherCompany = Company::factory()->create([
            'tenant_id' => $otherTenant->id,
            'ruc' => '0990000018002',
        ]);

        $otherFinding = FiscalFinding::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'code' => 'TOTALS_MISMATCH',
            'severity' => 'critical',
            'status' => 'open',
            'source_type' => 'fiscal_document',
            'source_id' => 2,
        ]);
    });

    $this->actingAs($this->owner)
        ->patchJson('/api/v1/fiscal-findings/'.$otherFinding->id, ['status' => 'resolved'])
        ->assertNotFound();
});

it('devuelve 403 sin permiso de revisión', function (): void {
    $viewer = User::factory()->create();
    $viewerRole = Role::firstOrCreate([
        'name' => MembershipRole::Viewer->value,
        'guard_name' => 'web',
        'tenant_id' => $this->tenant->id,
    ]);
    $viewer->assignRole($viewerRole);

    $this->actingAs($viewer)
        ->patchJson('/api/v1/fiscal-findings/'.$this->finding->id, ['status' => 'resolved'])
        ->assertForbidden();
});
