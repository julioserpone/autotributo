<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Membership;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'test']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
    ]);

    $this->makeUser = function (MembershipRole $role, ?Tenant $tenant = null): User {
        $tenant ??= $this->tenant;

        app(TenantContext::class)->initialize($tenant);

        $user = User::factory()->create();

        $roleModel = Role::firstOrCreate([
            'name' => $role->value,
            'guard_name' => 'web',
            'tenant_id' => $tenant->id,
        ]);

        $user->assignRole($roleModel);

        Membership::create([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'role' => $role->value,
        ]);

        return $user;
    };
});

it('aplica la matriz de la policy de empresas', function (
    MembershipRole $role,
    bool $canView,
    bool $canManage,
    bool $canArchive,
): void {
    $user = ($this->makeUser)($role);

    expect(Gate::forUser($user)->allows('view', $this->company))->toBe($canView)
        ->and(Gate::forUser($user)->allows('create', Company::class))->toBe($canManage)
        ->and(Gate::forUser($user)->allows('update', $this->company))->toBe($canManage)
        ->and(Gate::forUser($user)->allows('delete', $this->company))->toBe($canArchive);
})->with([
    'owner' => [MembershipRole::Owner, true, true, true],
    'accountant' => [MembershipRole::Accountant, true, true, true],
    'assistant' => [MembershipRole::Assistant, true, false, false],
    'viewer' => [MembershipRole::Viewer, true, false, false],
]);

it('aplica la matriz de la policy de sincronización', function (
    MembershipRole $role,
    bool $canView,
    bool $canStart,
    bool $canCancel,
): void {
    $user = ($this->makeUser)($role);

    $run = SriSyncRun::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'status' => SriSyncRunStatus::Queued,
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-30',
        'periods' => ['2026-09'],
        'document_types' => ['01'],
        'idempotency_key' => (string) Str::uuid(),
    ]);

    expect(Gate::forUser($user)->allows('view', $run))->toBe($canView)
        ->and(Gate::forUser($user)->allows('create', SriSyncRun::class))->toBe($canStart)
        ->and(Gate::forUser($user)->allows('retry', $run))->toBe($canStart)
        ->and(Gate::forUser($user)->allows('cancel', $run))->toBe($canCancel);
})->with([
    'owner' => [MembershipRole::Owner, true, true, true],
    'accountant' => [MembershipRole::Accountant, true, true, true],
    'assistant' => [MembershipRole::Assistant, true, true, true],
    'viewer' => [MembershipRole::Viewer, true, false, false],
]);

it('aplica la matriz de la policy de comprobantes', function (
    MembershipRole $role,
    bool $canView,
    bool $canReview,
    bool $canExport,
): void {
    $user = ($this->makeUser)($role);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
    ]);

    expect(Gate::forUser($user)->allows('view', $invoice))->toBe($canView)
        ->and(Gate::forUser($user)->allows('update', $invoice))->toBe($canReview)
        ->and(Gate::forUser($user)->allows('export', Invoice::class))->toBe($canExport);
})->with([
    'owner' => [MembershipRole::Owner, true, true, true],
    'accountant' => [MembershipRole::Accountant, true, true, true],
    'assistant' => [MembershipRole::Assistant, true, true, true],
    'viewer' => [MembershipRole::Viewer, true, false, false],
]);

it('aplica la matriz de la policy de miembros', function (
    MembershipRole $role,
    bool $canView,
    bool $canInvite,
    bool $canManage,
): void {
    $user = ($this->makeUser)($role);

    $membership = Membership::query()
        ->where('tenant_id', $this->tenant->id)
        ->where('user_id', $user->id)
        ->firstOrFail();

    expect(Gate::forUser($user)->allows('viewAny', Membership::class))->toBe($canView)
        ->and(Gate::forUser($user)->allows('invite', Membership::class))->toBe($canInvite)
        ->and(Gate::forUser($user)->allows('update', $membership))->toBe($canManage)
        ->and(Gate::forUser($user)->allows('delete', $membership))->toBe($canManage);
})->with([
    'owner' => [MembershipRole::Owner, true, true, true],
    'accountant' => [MembershipRole::Accountant, true, true, false],
    'assistant' => [MembershipRole::Assistant, false, false, false],
    'viewer' => [MembershipRole::Viewer, false, false, false],
]);

it('niega recursos de otro tenant aunque el rol sea owner', function (): void {
    $otherTenant = Tenant::factory()->create();
    $otherOwner = ($this->makeUser)(MembershipRole::Owner, $otherTenant);

    $run = SriSyncRun::create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'status' => SriSyncRunStatus::Queued,
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-30',
        'periods' => ['2026-09'],
        'document_types' => ['01'],
        'idempotency_key' => (string) Str::uuid(),
    ]);

    $invoice = Invoice::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
    ]);

    expect(Gate::forUser($otherOwner)->denies('view', $this->company))->toBeTrue()
        ->and(Gate::forUser($otherOwner)->denies('view', $run))->toBeTrue()
        ->and(Gate::forUser($otherOwner)->denies('view', $invoice))->toBeTrue()
        ->and(Gate::forUser($otherOwner)->denies('update', $this->company))->toBeTrue();
});
