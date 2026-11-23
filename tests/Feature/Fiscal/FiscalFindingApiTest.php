<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Models\FiscalFinding;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
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

it('expone empresa y comprobante de origen con identificadores públicos', function (): void {
    $document = FiscalDocument::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
    ]);

    $this->finding->update(['source_id' => $document->id]);

    $response = $this->actingAs($this->owner)->getJson('/api/v1/fiscal-findings');

    $response->assertOk()
        ->assertJsonPath('data.0.id', $this->finding->id)
        ->assertJsonPath('data.0.company.id', $this->company->public_id)
        ->assertJsonPath('data.0.company.legal_name', $this->company->legal_name)
        ->assertJsonPath('data.0.source.id', $document->public_id)
        ->assertJsonPath('data.0.source.issuer_ruc', $document->issuer_ruc)
        ->assertJsonMissingPath('data.0.company_id')
        ->assertJsonMissingPath('data.0.source_id');
});

it('filtra por public_id de empresa y por texto', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/fiscal-findings?filter[company_id]='.$this->company->public_id)
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($this->owner)
        ->getJson('/api/v1/fiscal-findings?filter[search]=inconsistentes')
        ->assertOk()
        ->assertJsonCount(1, 'data');

    $this->actingAs($this->owner)
        ->getJson('/api/v1/fiscal-findings?filter[search]=no-existe')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('devuelve contadores respetando el filtro de estado', function (): void {
    FiscalFinding::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'status' => 'resolved',
        'severity' => 'warning',
        'source_id' => 10,
    ]);

    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/fiscal-findings?filter[status]=open');

    $response->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('meta.counts.total', 1)
        ->assertJsonPath('meta.counts.by_status.open', 1)
        ->assertJsonPath('meta.counts.by_status.resolved', 0)
        ->assertJsonPath('meta.counts.by_severity.critical', 1)
        ->assertJsonPath('meta.counts.by_severity.warning', 0)
        ->assertJsonPath('meta.total', 1);
});

it('exige motivo para descartar un hallazgo', function (): void {
    $this->actingAs($this->owner)
        ->patchJson('/api/v1/fiscal-findings/'.$this->finding->id, ['status' => 'dismissed'])
        ->assertStatus(422)
        ->assertJsonValidationErrors('note');
});

it('descarta un hallazgo con motivo y lo devuelve en el recurso', function (): void {
    $response = $this->actingAs($this->owner)
        ->patchJson('/api/v1/fiscal-findings/'.$this->finding->id, [
            'status' => 'dismissed',
            'note' => 'Proveedor confirmado por el contribuyente',
        ]);

    $response->assertOk()
        ->assertJsonPath('data.status', 'dismissed')
        ->assertJsonPath('data.note', 'Proveedor confirmado por el contribuyente')
        ->assertJsonPath('data.company.id', $this->company->public_id);
});

it('no lista hallazgos de otro tenant', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'other']);

    app(TenantContext::class)->run($otherTenant, function () use ($otherTenant): void {
        $otherCompany = Company::factory()->create([
            'tenant_id' => $otherTenant->id,
            'ruc' => '0990000018003',
        ]);

        FiscalFinding::factory()->create([
            'tenant_id' => $otherTenant->id,
            'company_id' => $otherCompany->id,
            'source_id' => 30,
        ]);
    });

    $this->actingAs($this->owner)
        ->getJson('/api/v1/fiscal-findings')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $this->finding->id);
});
