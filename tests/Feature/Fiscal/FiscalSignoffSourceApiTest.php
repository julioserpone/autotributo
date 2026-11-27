<?php

declare(strict_types=1);

use App\Domain\Fiscal\Catalogs\FiscalSignoffStageCatalog;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'fiscal-signoff-source']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->owner = userWithSourceRole(MembershipRole::Owner);
    $this->accountant = userWithSourceRole(MembershipRole::Accountant);
    $this->assistant = userWithSourceRole(MembershipRole::Assistant);
    $this->viewer = userWithSourceRole(MembershipRole::Viewer);
});

afterEach(function (): void {
    app(TenantContext::class)->end();
});

it('allows reviewers to list source files for a stage', function (): void {
    $this->actingAs($this->accountant)
        ->getJson('/api/v1/fiscal-signoffs/iva-and-retention-catalogs/source-files')
        ->assertOk()
        ->assertJsonPath('data.0.id', 'fiscal-catalog-data')
        ->assertJsonPath('data.0.label', 'FiscalCatalogData.php')
        ->assertJsonPath('data.0.language', 'php')
        ->assertJsonMissingPath('data.0.path')
        ->assertJsonStructure(['data' => [['id', 'label', 'language', 'description', 'line_count', 'size_bytes']]]);
});

it('allows reviewers to read an allowed source file', function (): void {
    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/fiscal-signoffs/iva-and-retention-catalogs/source-files/fiscal-catalog-data')
        ->assertOk()
        ->assertJsonPath('data.id', 'fiscal-catalog-data')
        ->assertJsonPath('data.label', 'FiscalCatalogData.php')
        ->assertJsonStructure(['data' => ['id', 'label', 'language', 'description', 'content', 'line_count', 'size_bytes', 'sha256']]);

    expect($response->json('data.content'))->toContain('FiscalCatalogData');
    expect($response->json('data.sha256'))->toHaveLength(64);
});

it('returns not found for an unknown stage', function (): void {
    $this->actingAs($this->accountant)
        ->getJson('/api/v1/fiscal-signoffs/not-a-stage/source-files')
        ->assertNotFound();

    $this->actingAs($this->accountant)
        ->getJson('/api/v1/fiscal-signoffs/not-a-stage/source-files/fiscal-catalog-data')
        ->assertNotFound();
});

it('returns not found for an unknown source file', function (): void {
    $this->actingAs($this->accountant)
        ->getJson('/api/v1/fiscal-signoffs/iva-and-retention-catalogs/source-files/not-a-file')
        ->assertNotFound();
});

it('rejects path traversal attempts through the file id', function (): void {
    $this->actingAs($this->accountant)
        ->getJson('/api/v1/fiscal-signoffs/iva-and-retention-catalogs/source-files/..%2F..%2F.env')
        ->assertNotFound();
});

it('denies source access to assistants and viewers', function (): void {
    $this->actingAs($this->assistant)
        ->getJson('/api/v1/fiscal-signoffs/iva-and-retention-catalogs/source-files')
        ->assertForbidden();

    $this->actingAs($this->viewer)
        ->getJson('/api/v1/fiscal-signoffs/iva-and-retention-catalogs/source-files/fiscal-catalog-data')
        ->assertForbidden();
});

it('requires authentication for source endpoints', function (): void {
    $this->getJson('/api/v1/fiscal-signoffs/iva-and-retention-catalogs/source-files')->assertUnauthorized();
});

it('exposes source files in the stage payload without changing the fingerprint', function (): void {
    $stage = FiscalSignoffStageCatalog::find('iva-and-retention-catalogs');

    $this->actingAs($this->viewer)
        ->getJson('/api/v1/fiscal-signoffs')
        ->assertOk()
        ->assertJsonPath('data.0.source_files.0.id', 'fiscal-catalog-data');

    expect($stage['fingerprint'])->toBe(FiscalSignoffStageCatalog::fingerprint($stage));
});

function userWithSourceRole(MembershipRole $membershipRole): User
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
