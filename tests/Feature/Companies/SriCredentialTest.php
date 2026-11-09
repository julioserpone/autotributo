<?php

declare(strict_types=1);

use App\Domain\Companies\Actions\Credentials\ReadSriCredential;
use App\Domain\Companies\Exceptions\SriCredentialsMissing;
use App\Domain\Companies\Models\Company;
use App\Domain\Companies\Models\SriCredential;
use App\Domain\Companies\Models\SriCredentialAccess;
use App\Domain\Sri\Contracts\SriRobotGateway;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Integrations\Sri\FakeSriRobotGateway;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'creds-test']);
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
    $this->viewer = ($this->makeUser)(MembershipRole::Viewer);

    $this->company = Company::factory()->create([
        'tenant_id' => $this->tenant->id,
        'ruc' => '0990000018001',
    ]);

    $this->gateway = new FakeSriRobotGateway;
    $this->app->instance(SriRobotGateway::class, $this->gateway);
});

it('crea la credencial sin devolver el secreto y la deja auditada', function (): void {
    $response = $this->actingAs($this->owner)
        ->putJson('/api/v1/companies/'.$this->company->public_id.'/credential', [
            'username' => '0990000018001',
            'password' => 'secreto-123',
            'password_confirmation' => 'secreto-123',
        ]);

    $response->assertCreated()
        ->assertJsonPath('data.username', '0990000018001')
        ->assertJsonPath('data.has_password', true)
        ->assertJsonPath('data.status', SriCredential::STATUS_ACTIVE)
        ->assertJsonMissingPath('data.password')
        ->assertJsonMissingPath('data.password_ciphertext');

    expect($response->json())->not->toContain('secreto-123');

    $credential = SriCredential::query()->firstOrFail();

    expect($credential->password_ciphertext)->not->toContain('secreto-123')
        ->and(json_encode($credential))->not->toContain($credential->password_ciphertext)
        ->and(SriCredentialAccess::query()->count())->toBe(1);
});

it('muestra la credencial existente sin exponer el secreto', function (): void {
    $this->actingAs($this->owner)->putJson(credentialUrl($this->company), credentialPayload());

    $this->actingAs($this->owner)
        ->getJson(credentialUrl($this->company))
        ->assertOk()
        ->assertJsonPath('data.has_password', true)
        ->assertJsonMissingPath('data.password_ciphertext');
});

it('rota la contraseña y actualiza rotated_at', function (): void {
    $this->actingAs($this->owner)->putJson(credentialUrl($this->company), credentialPayload());
    $before = SriCredential::query()->firstOrFail()->password_ciphertext;

    $this->actingAs($this->owner)
        ->postJson(credentialUrl($this->company).'/rotate', [
            'username' => '0990000018001',
            'password' => 'nueva-clave',
            'password_confirmation' => 'nueva-clave',
        ])
        ->assertOk()
        ->assertJsonPath('data.has_password', true);

    $after = SriCredential::query()->firstOrFail();

    expect($after->password_ciphertext)->not->toBe($before)
        ->and($after->rotated_at)->not->toBeNull();
});

it('rechaza rotar si no existe credencial', function (): void {
    $this->actingAs($this->owner)
        ->postJson(credentialUrl($this->company).'/rotate', [
            'username' => '0990000018001',
            'password' => 'nueva-clave',
            'password_confirmation' => 'nueva-clave',
        ])
        ->assertStatus(409);
});

it('borra la credencial y deja rastro del borrado', function (): void {
    $this->actingAs($this->owner)->putJson(credentialUrl($this->company), credentialPayload());

    $this->actingAs($this->owner)
        ->deleteJson(credentialUrl($this->company))
        ->assertNoContent();

    expect(SriCredential::query()->count())->toBe(0)
        ->and(SriCredentialAccess::query()->count())->toBe(2);
});

it('exige que el usuario coincida con el RUC de la empresa', function (): void {
    $this->actingAs($this->owner)
        ->putJson(credentialUrl($this->company), credentialPayload(['username' => '1758871873001']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('username');
});

it('exige confirmación de contraseña', function (): void {
    $this->actingAs($this->owner)
        ->putJson(credentialUrl($this->company), credentialPayload([
            'password_confirmation' => 'otra',
        ]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors('password');
});

it('impide que un viewer lea o escriba credenciales', function (): void {
    $this->actingAs($this->owner)->putJson(credentialUrl($this->company), credentialPayload());

    $this->actingAs($this->viewer)->getJson(credentialUrl($this->company))->assertForbidden();
    $this->actingAs($this->viewer)->putJson(credentialUrl($this->company), credentialPayload())->assertForbidden();
    $this->actingAs($this->viewer)->deleteJson(credentialUrl($this->company))->assertForbidden();
});

it('aísla las credenciales entre tenants', function (): void {
    $this->actingAs($this->owner)->putJson(credentialUrl($this->company), credentialPayload());

    $otherTenant = Tenant::factory()->create(['slug' => 'otro']);
    app(TenantContext::class)->initialize($otherTenant);

    $foreign = Company::factory()->create([
        'tenant_id' => $otherTenant->id,
        'ruc' => '1790012345001',
    ]);

    // El `User` es global: el owner del primer tenant conserva su membresía,
    // pero la empresa ajena no existe bajo el tenant activo. Se verifica en el
    // contexto del primer tenant para que el binding la resuelva y sea la capa
    // de autorización la que responda sin filtrar su existencia.
    app(TenantContext::class)->initialize($this->tenant);

    $this->actingAs($this->owner)->getJson(credentialUrl($foreign))->assertNotFound();
    $this->actingAs($this->owner)->putJson(credentialUrl($foreign), credentialPayload([
        'username' => '1790012345001',
    ]))->assertNotFound();
    $this->actingAs($this->owner)->deleteJson(credentialUrl($foreign))->assertNotFound();
});

it('archivar la empresa elimina su credencial', function (): void {
    $this->actingAs($this->owner)->putJson(credentialUrl($this->company), credentialPayload());

    $this->actingAs($this->owner)
        ->deleteJson('/api/v1/companies/'.$this->company->public_id)
        ->assertNoContent();

    expect(SriCredential::query()->count())->toBe(0);
});

it('registra el acceso al descifrar y devuelve las credenciales del robot', function (): void {
    $this->actingAs($this->owner)->putJson(credentialUrl($this->company), credentialPayload());

    $credentials = app(ReadSriCredential::class)->handle($this->company, userId: $this->owner->id);

    expect($credentials->ruc)->toBe('0990000018001')
        ->and($credentials->toSealingPayload()['password'])->toBe('secreto-123')
        ->and(SriCredentialAccess::query()->where('purpose', 'sync')->count())->toBe(1);
});

it('falla al leer cuando no hay credencial activa', function (): void {
    expect(fn () => app(ReadSriCredential::class)->handle($this->company))
        ->toThrow(SriCredentialsMissing::class);
});

it('no registra el secreto ni el blob en los logs', function (): void {
    Log::spy();

    $this->actingAs($this->owner)->putJson(credentialUrl($this->company), credentialPayload());

    app(ReadSriCredential::class)->handle($this->company);

    Log::shouldNotHaveReceived('info', fn (string $message, array $context = []): bool => str_contains(json_encode($context) ?: '', 'secreto-123'));
});

it('inicia una sincronización con la credencial persistida', function (): void {
    $this->actingAs($this->owner)->putJson(credentialUrl($this->company), credentialPayload());

    $response = $this->actingAs($this->owner)
        ->withHeader('Idempotency-Key', 'idem-api-1')
        ->postJson('/api/v1/sync-runs', [
            'company_id' => $this->company->public_id,
            'periods' => ['2026-08'],
            'document_types' => ['01'],
        ]);

    $response->assertStatus(202)
        ->assertJsonPath('meta.count', 1)
        ->assertJsonPath('data.0.status', 'invoking')
        ->assertHeader('Location');

    expect(SriSyncRun::query()->count())->toBe(1)
        ->and($this->gateway->requests)->toHaveCount(1)
        ->and($this->gateway->requests[0]->credentials?->toSealingPayload()['password'])->toBe('secreto-123');
});

it('responde 409 cuando la empresa no tiene credenciales', function (): void {
    $this->actingAs($this->owner)
        ->withHeader('Idempotency-Key', 'idem-sin-credencial')
        ->postJson('/api/v1/sync-runs', [
            'company_id' => $this->company->public_id,
            'periods' => ['2026-08'],
        ])
        ->assertStatus(409)
        ->assertHeader('Content-Type', 'application/problem+json')
        ->assertJsonPath('type', 'https://autotributo.com/problems/sri-credentials-missing')
        ->assertJsonPath('detail', fn (string $detail): bool => str_contains($detail, 'no tiene credenciales'));

    expect(SriSyncRun::query()->count())->toBe(0);
});

it('rechaza un company_id de otro tenant y no filtra si existe', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'ajeno']);
    app(TenantContext::class)->initialize($otherTenant);

    $foreign = Company::factory()->create([
        'tenant_id' => $otherTenant->id,
        'ruc' => '1790012345001',
    ]);

    app(TenantContext::class)->initialize($this->tenant);

    $this->actingAs($this->owner)
        ->withHeader('Idempotency-Key', 'idem-ajeno')
        ->postJson('/api/v1/sync-runs', [
            'company_id' => $foreign->public_id,
            'periods' => ['2026-08'],
        ])
        ->assertUnprocessable()
        ->assertJsonValidationErrors('company_id');
});

it('exige Idempotency-Key en sync-runs', function (): void {
    $this->actingAs($this->owner)->putJson(credentialUrl($this->company), credentialPayload());

    $this->actingAs($this->owner)
        ->postJson('/api/v1/sync-runs', [
            'company_id' => $this->company->public_id,
            'periods' => ['2026-08'],
        ])
        ->assertForbidden();

    expect(SriSyncRun::query()->count())->toBe(0)
        ->and($this->gateway->requests)->toBeEmpty();
});

it('rechaza un run solapado con 409 y el run existente', function (): void {
    $this->actingAs($this->owner)->putJson(credentialUrl($this->company), credentialPayload());

    $payload = [
        'company_id' => $this->company->public_id,
        'periods' => ['2026-08'],
    ];

    $first = $this->actingAs($this->owner)
        ->withHeader('Idempotency-Key', 'idem-conflicto')
        ->postJson('/api/v1/sync-runs', $payload)
        ->assertStatus(202);

    $this->actingAs($this->owner)
        ->withHeader('Idempotency-Key', 'idem-otra')
        ->postJson('/api/v1/sync-runs', $payload)
        ->assertStatus(409)
        ->assertJsonPath('existing_run_id', $first->json('data.0.id'));
});

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function credentialPayload(array $overrides = []): array
{
    return array_merge([
        'username' => '0990000018001',
        'password' => 'secreto-123',
        'password_confirmation' => 'secreto-123',
    ], $overrides);
}

function credentialUrl(Company $company): string
{
    return '/api/v1/companies/'.$company->public_id.'/credential';
}
