<?php

declare(strict_types=1);

use App\Domain\Audit\Models\AuditLog;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\SriCredential;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'audit-test']);
    app(TenantContext::class)->initialize($this->tenant);

    $this->makeUser = function (MembershipRole $role): User {
        app(TenantContext::class)->initialize($this->tenant);

        $user = User::factory()->create();

        $user->assignRole(Role::firstOrCreate([
            'name' => $role->value,
            'guard_name' => 'web',
            'tenant_id' => $this->tenant->id,
        ]));

        return $user;
    };

    $this->owner = ($this->makeUser)(MembershipRole::Owner);
    $this->assistant = ($this->makeUser)(MembershipRole::Assistant);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '0990000018001',
        'legal_name' => 'Estudio Contable Demo S.A.',
    ]);
});

it('audita los cambios de una empresa con valores antes y después', function (): void {
    $this->actingAs($this->owner)
        ->putJson('/api/v1/companies/'.$this->company->public_id, [
            'legal_name' => 'Razón Social Actualizada S.A.',
        ])
        ->assertOk();

    $log = AuditLog::query()
        ->where('auditable_type', Company::class)
        ->where('event', 'updated')
        ->latest('id')
        ->firstOrFail();

    expect($log->tenant_id)->toBe($this->tenant->id)
        ->and($log->user_id)->toBe($this->owner->id)
        ->and($log->old_values['legal_name'])->toBe('Estudio Contable Demo S.A.')
        ->and($log->new_values['legal_name'])->toBe('Razón Social Actualizada S.A.');
});

it('nunca guarda el blob cifrado de la credencial en el historial', function (): void {
    $this->actingAs($this->owner)->putJson(
        '/api/v1/companies/'.$this->company->public_id.'/credential',
        [
            'username' => '0990000018001',
            'password' => 'secreto-123',
            'password_confirmation' => 'secreto-123',
        ],
    )->assertCreated();

    $credential = SriCredential::query()->firstOrFail();

    $logs = AuditLog::query()
        ->where('auditable_type', SriCredential::class)
        ->get();

    expect($logs)->not->toBeEmpty();

    foreach ($logs as $log) {
        $serialized = json_encode([$log->old_values, $log->new_values]);

        expect($serialized)->not->toContain('secreto-123')
            ->and($serialized)->not->toContain($credential->password_ciphertext);
    }
});

it('registra el login como evento de auditoría', function (): void {
    $this->postJson('/api/v1/auth/login', [
        'email' => $this->owner->email,
        'password' => 'password',
    ])->assertOk();

    expect(AuditLog::query()->where('event', 'login')->where('user_id', $this->owner->id)->exists())
        ->toBeTrue();
});

it('expone el historial solo a roles con audit.view', function (): void {
    $this->actingAs($this->owner)
        ->putJson('/api/v1/companies/'.$this->company->public_id, ['legal_name' => 'Nueva S.A.'])
        ->assertOk();

    $this->actingAs($this->owner)
        ->getJson('/api/v1/audit-logs')
        ->assertOk()
        ->assertJsonPath('data.0.auditable_type', 'Company')
        ->assertJsonStructure(['data' => [['id', 'event', 'auditable_type', 'auditable_id', 'created_at']]]);

    $this->actingAs($this->assistant)
        ->getJson('/api/v1/audit-logs')
        ->assertForbidden();
});

it('filtra el historial por tenant', function (): void {
    $this->actingAs($this->owner)
        ->putJson('/api/v1/companies/'.$this->company->public_id, ['legal_name' => 'Propia S.A.'])
        ->assertOk();

    $otherTenant = Tenant::factory()->create(['slug' => 'audit-otro']);
    app(TenantContext::class)->initialize($otherTenant);

    $foreign = Company::factory()->create([
        'tenant_id' => $otherTenant->id,
        'ruc' => '1790012345001',
    ]);

    $foreign->forceFill(['legal_name' => 'Ajena S.A.'])->save();

    app(TenantContext::class)->initialize($this->tenant);

    $response = $this->actingAs($this->owner)->getJson('/api/v1/audit-logs')->assertOk();

    $names = collect($response->json('data'))->pluck('new_values.legal_name')->filter()->all();

    expect($names)->toContain('Propia S.A.')
        ->and($names)->not->toContain('Ajena S.A.');
});

it('filtra por evento y tipo de recurso', function (): void {
    $this->actingAs($this->owner)
        ->putJson('/api/v1/companies/'.$this->company->public_id, ['legal_name' => 'Filtrada S.A.'])
        ->assertOk();

    $this->actingAs($this->owner)
        ->getJson('/api/v1/audit-logs?event=updated&auditable_type=Company')
        ->assertOk()
        ->assertJsonPath('data.0.event', 'updated')
        ->assertJsonPath('data.0.auditable_type', 'Company');
});
