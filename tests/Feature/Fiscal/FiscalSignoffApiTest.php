<?php

declare(strict_types=1);

use App\Domain\Fiscal\Catalogs\FiscalSignoffStageCatalog;
use App\Domain\Fiscal\Enums\FiscalSignoffDecision;
use App\Domain\Fiscal\Models\FiscalSignoffReview;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'fiscal-signoffs']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->owner = userWithRole(MembershipRole::Owner);
    $this->accountant = userWithRole(MembershipRole::Accountant);
    $this->assistant = userWithRole(MembershipRole::Assistant);
    $this->viewer = userWithRole(MembershipRole::Viewer);
});

afterEach(function (): void {
    app(TenantContext::class)->end();
});

it('lists versioned stages and lets the viewer inspect pending approvals', function (): void {
    $this->actingAs($this->viewer)
        ->getJson('/api/v1/fiscal-signoffs')
        ->assertOk()
        ->assertJsonCount(9, 'data')
        ->assertJsonPath('data.0.status', 'pending')
        ->assertJsonPath('data.0.version', 1)
        ->assertJsonPath('data.0.latest_review', null);
});

it('isolates approval history by tenant and records decisions in the active tenant', function (): void {
    $stage = FiscalSignoffStageCatalog::find('ats-iva-nature-mapping');
    $otherTenant = Tenant::factory()->create(['slug' => 'other-fiscal-signoffs']);
    $otherReviewer = User::factory()->create();

    app(TenantContext::class)->run($otherTenant, function () use ($otherTenant, $otherReviewer, $stage): void {
        FiscalSignoffReview::query()->create([
            'tenant_id' => $otherTenant->id,
            'reviewer_id' => $otherReviewer->id,
            'stage_key' => 'ats-iva-nature-mapping',
            'stage_version' => 1,
            'stage_fingerprint' => (string) $stage['fingerprint'],
            'decision' => FiscalSignoffDecision::Approved,
            'comments' => 'Revisión de otro tenant.',
            'reviewed_at' => now(),
        ]);
    });

    $this->actingAs($this->viewer)
        ->getJson('/api/v1/fiscal-signoffs')
        ->assertOk()
        ->assertJsonPath('data.7.status', 'pending')
        ->assertJsonCount(0, 'data.7.history');

    $this->actingAs($this->accountant)
        ->postJson('/api/v1/fiscal-signoffs/ats-iva-nature-mapping/reviews', [
            'decision' => 'approved',
            'stage_version' => 1,
            'stage_fingerprint' => $stage['fingerprint'],
        ])
        ->assertCreated();

    $this->assertDatabaseHas('fiscal_signoff_reviews', [
        'tenant_id' => $this->tenant->id,
        'reviewer_id' => $this->accountant->id,
        'stage_key' => 'ats-iva-nature-mapping',
    ]);
});

it('allows an accountant to approve a stage and records the authenticated reviewer', function (): void {
    $this->actingAs($this->accountant)
        ->postJson('/api/v1/fiscal-signoffs/ats-iva-nature-mapping/reviews', [
            'decision' => 'approved',
            'stage_version' => 1,
            'stage_fingerprint' => FiscalSignoffStageCatalog::find('ats-iva-nature-mapping')['fingerprint'],
            'comments' => 'Revisado contra la tabla ATS.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.stage_key', 'ats-iva-nature-mapping')
        ->assertJsonPath('data.stage_version', 1)
        ->assertJsonPath('data.decision', 'approved')
        ->assertJsonPath('data.reviewed_by.name', $this->accountant->name)
        ->assertJsonPath('data.comments', 'Revisado contra la tabla ATS.');

    $this->actingAs($this->viewer)
        ->getJson('/api/v1/fiscal-signoffs')
        ->assertOk()
        ->assertJsonPath('data.7.status', 'approved')
        ->assertJsonPath('data.7.history.0.current_version', true);
});

it('requires observations when a stage is returned for changes', function (): void {
    $this->actingAs($this->accountant)
        ->postJson('/api/v1/fiscal-signoffs/form104-complete-scope/reviews', [
            'decision' => 'changes_requested',
            'stage_version' => 3,
            'stage_fingerprint' => FiscalSignoffStageCatalog::find('form104-complete-scope')['fingerprint'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('comments');

    $this->actingAs($this->accountant)
        ->postJson('/api/v1/fiscal-signoffs/form104-complete-scope/reviews', [
            'decision' => 'changes_requested',
            'stage_version' => 3,
            'stage_fingerprint' => FiscalSignoffStageCatalog::find('form104-complete-scope')['fingerprint'],
            'comments' => 'Separar la tarifa temporal por fecha de emisión.',
        ])
        ->assertCreated()
        ->assertJsonPath('data.decision', 'changes_requested');
});

it('allows owners to review, denies assistants, and preserves each decision in history', function (): void {
    $this->actingAs($this->assistant)
        ->postJson('/api/v1/fiscal-signoffs/expense-categories/reviews', [
            'decision' => 'approved',
            'stage_version' => 1,
            'stage_fingerprint' => FiscalSignoffStageCatalog::find('expense-categories')['fingerprint'],
        ])
        ->assertForbidden();

    $this->actingAs($this->owner)
        ->postJson('/api/v1/fiscal-signoffs/expense-categories/reviews', [
            'decision' => 'approved',
            'stage_version' => 1,
            'stage_fingerprint' => FiscalSignoffStageCatalog::find('expense-categories')['fingerprint'],
        ])
        ->assertCreated();

    $this->actingAs($this->accountant)
        ->postJson('/api/v1/fiscal-signoffs/expense-categories/reviews', [
            'decision' => 'changes_requested',
            'stage_version' => 1,
            'stage_fingerprint' => FiscalSignoffStageCatalog::find('expense-categories')['fingerprint'],
            'comments' => 'Ajustar la categoría de servicios profesionales.',
        ])
        ->assertCreated();

    $this->actingAs($this->viewer)
        ->getJson('/api/v1/fiscal-signoffs')
        ->assertOk()
        ->assertJsonPath('data.1.status', 'changes_requested')
        ->assertJsonCount(2, 'data.1.history');
});

it('returns not found for a stage that is not in the signoff catalog', function (): void {
    $this->actingAs($this->accountant)
        ->postJson('/api/v1/fiscal-signoffs/not-a-stage/reviews', [
            'decision' => 'approved',
            'stage_version' => 1,
            'stage_fingerprint' => str_repeat('a', 64),
        ])
        ->assertNotFound();
});

it('rejects approval of a stage snapshot that is no longer current', function (): void {
    $this->actingAs($this->accountant)
        ->postJson('/api/v1/fiscal-signoffs/ats-iva-nature-mapping/reviews', [
            'decision' => 'approved',
            'stage_version' => 1,
            'stage_fingerprint' => str_repeat('a', 64),
        ])
        ->assertConflict()
        ->assertJsonPath('type', 'https://autotributo.com/problems/fiscal-signoff-stage-changed')
        ->assertJsonPath('detail', 'La etapa cambió desde que se cargó. Actualiza la página y vuelve a revisarla antes de aprobar.');
});

it('requires authentication to access the signoff workflow', function (): void {
    $this->getJson('/api/v1/fiscal-signoffs')->assertUnauthorized();
});

function userWithRole(MembershipRole $membershipRole): User
{
    $user = User::factory()->create();
    $role = Role::firstOrCreate([
        'name' => $membershipRole->value,
        'guard_name' => 'web',
        'tenant_id' => app(TenantContext::class)->id(),
    ]);

    $user->assignRole($role);

    return $user;
}
