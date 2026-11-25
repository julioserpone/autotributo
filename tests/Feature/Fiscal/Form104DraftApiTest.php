<?php

declare(strict_types=1);

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Actions\SyncFiscalCatalogs;
use App\Domain\Fiscal\Models\FiscalFinding;
use App\Domain\Fiscal\Models\FiscalPeriod;
use App\Domain\Fiscal\Models\FormLine;
use App\Domain\Fiscal\Models\PeriodAggregate;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Tenancy\Enums\MembershipRole;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;

uses(RefreshDatabase::class);

/**
 * @param  list<array<string, mixed>>  $lines
 * @return array<string, mixed>
 */
function form104Line(array $lines, string $field): array
{
    foreach ($lines as $line) {
        if ($line['field'] === $field) {
            return $line;
        }
    }

    throw new RuntimeException("Campo {$field} no presente en el borrador.");
}

function form104Aggregate(int $tenantId, int $companyId, int $periodId, array $overrides = []): PeriodAggregate
{
    return PeriodAggregate::query()->create([
        'tenant_id' => $tenantId,
        'company_id' => $companyId,
        'fiscal_period_id' => $periodId,
        'direction' => 'sales',
        'document_type' => SriDocumentType::FiscalDocument->value,
        'tax_code' => '2',
        'sri_percentage_code' => '4',
        'rate' => 15,
        'base_cents' => 100_000,
        'tax_cents' => 15_000,
        'retention_cents' => 0,
        'documents_count' => 1,
        'computed_at' => now(),
        ...$overrides,
    ]);
}

beforeEach(function (): void {
    $this->tenant = Tenant::factory()->create(['slug' => 'form-104-test']);
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
        'ruc' => '1790016919001',
    ]);

    app(SyncFiscalCatalogs::class)->handle();

    $this->period = FiscalPeriod::query()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'year' => 2026,
        'month' => 9,
        'status' => 'open',
        'opened_at' => now(),
    ]);

    $this->issuedDocument = FiscalDocument::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'direction' => FiscalDocument::DIRECTION_ISSUED,
        'issuer_ruc' => $this->company->ruc,
        'issue_date' => '2026-09-10',
        'total_cents' => 11_500,
    ]);
});

afterEach(function (): void {
    app(TenantContext::class)->end();
});

it('devuelve los campos del formulario oficial del 104 en su orden', function (): void {
    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/'.$this->company->public_id.'/form-104?year=2026&month=9');

    $response->assertOk()
        ->assertJsonPath('data.period.form_code', '104')
        ->assertJsonPath('data.period.resolution', 'NAC-DGERCGC16-00000210')
        ->assertJsonPath('data.status.draft', true)
        ->assertJsonPath('data.status.editable', false);

    $fields = array_column($response->json('data.lines'), 'field');

    // Comienzo del formulario y transición entre secciones del XLS oficial.
    expect($fields[0])->toBe('401')
        ->and($fields)->toContain('409', '419', '429', '441')
        ->and($fields)->toContain('480', '499')
        ->and($fields)->toContain('500', '563', '564')
        ->and($fields)->toContain('601', '620', '699')
        ->and($fields)->toContain('721', '801')
        ->and($fields)->toContain('859', '902', '999')
        ->and($fields[count($fields) - 1])->toBe('999');

    expect($response->json('data.official_form.sections'))->toHaveCount(6);
});

it('calcula ventas, IVA de compras, retenciones y crédito con fuente para drill-down', function (): void {
    // Ventas: 100.000 al 15 % y 20.000 al 0 %.
    form104Aggregate($this->tenant->id, $this->company->id, $this->period->id);

    form104Aggregate($this->tenant->id, $this->company->id, $this->period->id, [
        'sri_percentage_code' => '0',
        'rate' => 0,
        'base_cents' => 20_000,
        'tax_cents' => 0,
        'documents_count' => 2,
    ]);

    // Compras: 40.000 al 15 % con IVA de 6.000.
    form104Aggregate($this->tenant->id, $this->company->id, $this->period->id, [
        'direction' => 'purchases',
        'base_cents' => 40_000,
        'tax_cents' => 6_000,
        'documents_count' => 3,
    ]);

    // Retención de IVA practicada: 721 al 10 % sobre 10.000.
    form104Aggregate($this->tenant->id, $this->company->id, $this->period->id, [
        'direction' => 'purchases',
        'document_type' => SriDocumentType::Withholding->value,
        'tax_code' => '721',
        'sri_percentage_code' => '',
        'rate' => 10,
        'base_cents' => 10_000,
        'tax_cents' => 0,
        'retention_cents' => 1_000,
        'documents_count' => 1,
    ]);

    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/'.$this->company->public_id.'/form-104?year=2026&month=9');

    $response->assertOk();

    $lines = $response->json('data.lines');

    expect(form104Line($lines, '401')['value_cents'])->toBe(100_000)
        ->and(form104Line($lines, '401')['sources'][0]['filter']['direction'])->toBe('ventas')
        ->and(form104Line($lines, '401')['sources'][0]['filter']['date_from'])->toBe('2026-09-01')
        ->and(form104Line($lines, '421')['value_cents'])->toBe(15_000)
        ->and(form104Line($lines, '405')['value_cents'])->toBe(20_000)
        ->and(form104Line($lines, '409')['value_cents'])->toBe(120_000)
        ->and(form104Line($lines, '529')['value_cents'])->toBe(6_000)
        ->and(form104Line($lines, '529')['sources'][0]['filter']['direction'])->toBe('compras');

    foreach (['500', '501', '502', '509', '510', '511', '517', '519', '520', '521', '522'] as $field) {
        expect(form104Line($lines, $field)['unavailable'])->toBeTrue()
            ->and(form104Line($lines, $field)['value_cents'])->toBeNull();
    }

    expect(form104Line($lines, '721')['value_cents'])->toBe(1_000)
        ->and(form104Line($lines, '799')['value_cents'])->toBe(1_000);

    // El factor de proporcionalidad se informa como ratio en el campo 563.
    expect(form104Line($lines, '563')['rate'])->toBe('0.166667')
        ->and(form104Line($lines, '564')['value_cents'])->toBe(5_000);

    // Los casilleros que dependen del cierre o del contador no se muestran como 0.
    expect(form104Line($lines, '601')['unavailable'])->toBeTrue()
        ->and(form104Line($lines, '601')['value_cents'])->toBeNull()
        ->and(form104Line($lines, '902')['unavailable'])->toBeTrue();

    expect(form104Line($lines, '421')['requires_signoff'])->toBeTrue()
        ->and(form104Line($lines, '564')['requires_signoff'])->toBeTrue()
        ->and(form104Line($lines, '401')['requires_signoff'])->toBeFalse();
});

it('marca los campos de pago como de entrada manual', function (): void {
    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/'.$this->company->public_id.'/form-104?year=2026&month=9');

    $response->assertOk();

    $lines = $response->json('data.lines');

    expect(form104Line($lines, '890')['manual'])->toBeTrue()
        ->and(form104Line($lines, '898')['manual'])->toBeTrue()
        ->and(form104Line($lines, '903')['manual'])->toBeTrue()
        ->and(form104Line($lines, '401')['manual'])->toBeFalse();
});

it('calcula el factor de proporcionalidad con la convención de F3-07', function (): void {
    // Ventas con tarifa general (100.000 al 15 %) y 100.000 al 0 %: el factor
    // es 100.000 / 200.000 = 0.500000.
    form104Aggregate($this->tenant->id, $this->company->id, $this->period->id);

    form104Aggregate($this->tenant->id, $this->company->id, $this->period->id, [
        'sri_percentage_code' => '0',
        'rate' => 0,
        'base_cents' => 100_000,
        'tax_cents' => 0,
    ]);

    form104Aggregate($this->tenant->id, $this->company->id, $this->period->id, [
        'direction' => 'purchases',
        'base_cents' => 50_000,
        'tax_cents' => 7_500,
    ]);

    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/'.$this->company->public_id.'/form-104?year=2026&month=9');

    $response->assertOk()
        ->assertJsonPath('data.proportionality.sales_zero', 100_000)
        ->assertJsonPath('data.proportionality.sales_general', 100_000)
        ->assertJsonPath('data.proportionality.proportionality_factor', '0.500000')
        ->assertJsonPath('data.proportionality.proportionality_complement', '0.500000')
        ->assertJsonPath('data.proportionality.purchases_common_tax_to_credit', 3_750);
});

it('devuelve ceros y proporcionalidad nula cuando el período no tiene comprobantes', function (): void {
    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/'.$this->company->public_id.'/form-104?year=2026&month=3');

    $response->assertOk()
        ->assertJsonPath('data.period.has_period', false)
        ->assertJsonPath('data.period.status', null)
        ->assertJsonPath('data.proportionality.proportionality_factor', null);

    $lines = $response->json('data.lines');

    expect(form104Line($lines, '401')['value_cents'])->toBe(0)
        ->and(form104Line($lines, '529')['value_cents'])->toBe(0)
        ->and(form104Line($lines, '520')['value_cents'])->toBeNull()
        ->and(form104Line($lines, '520')['unavailable'])->toBeTrue()
        ->and(form104Line($lines, '564')['value_cents'])->toBe(0);
});

it('usa los casilleros del catálogo vigentes en el último día del período', function (): void {
    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/'.$this->company->public_id.'/form-104?year=2026&month=9');

    $response->assertOk();

    $expected = FormLine::query()
        ->where('form_code', '104')
        ->where('valid_from', '<=', '2026-09-30')
        ->count();

    expect($expected)->toBeGreaterThan(70);
    expect($response->json('data.lines'))->toHaveCount(88);
});

it('cuenta los hallazgos abiertos del período como bloqueantes del cierre', function (): void {
    FiscalFinding::factory()->create([
        'tenant_id' => $this->tenant->id,
        'company_id' => $this->company->id,
        'code' => 'TOTALS_MISMATCH',
        'severity' => 'critical',
        'status' => 'open',
        'source_type' => 'fiscal_document',
        'source_id' => $this->issuedDocument->id,
    ]);

    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/'.$this->company->public_id.'/form-104?year=2026&month=9');

    $response->assertOk()
        ->assertJsonPath('data.findings.0.code', 'TOTALS_MISMATCH')
        ->assertJsonPath('data.findings.0.total', 1)
        ->assertJsonPath('data.findings.0.severity', 'critical');
});

it('acepta el company_id por query en la ruta sin empresa', function (): void {
    $response = $this->actingAs($this->owner)
        ->getJson('/api/v1/form-104?company_id='.$this->company->public_id.'&year=2026&month=9');

    $response->assertOk()
        ->assertJsonPath('data.company.public_id', $this->company->public_id);
});

it('devuelve 404 para una empresa de otro tenant', function (): void {
    $otherTenant = Tenant::factory()->create(['slug' => 'other-form-104']);

    app(TenantContext::class)->run($otherTenant, function () use ($otherTenant, &$otherCompany): void {
        $otherCompany = Company::factory()->create([
            'tenant_id' => $otherTenant->id,
            'ruc' => '0990000018002',
        ]);
    });

    $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/'.$otherCompany->public_id.'/form-104')
        ->assertNotFound();
});

it('valida el período solicitado', function (): void {
    $this->actingAs($this->owner)
        ->getJson('/api/v1/companies/'.$this->company->public_id.'/form-104?year=2026&month=13')
        ->assertUnprocessable();
});
