<?php

declare(strict_types=1);

namespace Database\Seeders;

use App\Domain\Classification\Actions\SyncExpenseCategories;
use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Classification\Models\Classification;
use App\Domain\Classification\Models\ExpenseCategory;
use App\Domain\Companies\Models\Company;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\FiscalDocuments\Models\FiscalDocumentItem;
use App\Domain\Tenancy\Actions\EnsureTenantRoles;
use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

/**
 * Escenarios de clasificación para desarrollo y QA de la cola de revisión (F4-07).
 *
 * Crea un estudio, una empresa y un comprobante con varios ítems, cada uno con
 * una clasificación en un estado/método/confianza distinto, para poder ejercitar
 * la UI sin depender de un sync real del SRI.
 *
 * Es idempotente: si ya existen clasificaciones para la empresa demo, no
 * duplica filas.
 */
final class ClassificationScenarioSeeder extends Seeder
{
    public function run(): void
    {
        $tenant = Tenant::query()->firstOrCreate(
            ['slug' => (string) config('tenancy.default_slug')],
            [
                'name' => (string) config('app.name', 'Autotributo'),
                'status' => Tenant::STATUS_ACTIVE,
                'plan' => 'pro',
                'settings' => [],
            ],
        );

        app(TenantContext::class)->initialize($tenant);
        app(EnsureTenantRoles::class)->handle($tenant);

        $owner = User::query()->firstOrCreate(
            ['email' => 'owner@autotributo.test'],
            [
                'name' => 'Owner de prueba',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
            ],
        );
        $owner->assignRole('owner');

        $company = Company::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'ruc' => '0990000018001'],
            [
                'legal_name' => 'EMPRESA DE PRUEBA S.A.',
                'trade_name' => 'Empresa de prueba',
                'regime' => 'general',
                'is_accounting_required' => true,
                'is_withholding_agent' => false,
                'fiscal_year_start_month' => 1,
                'status' => Company::STATUS_ACTIVE,
                'settings' => [],
                'created_by' => $owner->getKey(),
            ],
        );

        app(SyncExpenseCategories::class)->handle();

        $categories = ExpenseCategory::query()->pluck('id', 'code');

        if (Classification::query()->where('tenant_id', $tenant->id)->exists()) {
            $this->command?->info('ClassificationScenarioSeeder: ya existen clasificaciones, se omite.');

            return;
        }

        $document = FiscalDocument::query()->firstOrCreate(
            ['tenant_id' => $tenant->id, 'company_id' => $company->id, 'access_key' => str_repeat('1', 49)],
            [
                'document_type' => '01',
                'authorization_number' => '1234567890',
                'establishment' => '001',
                'emission_point' => '001',
                'sequential' => '000000001',
                'issue_date' => now()->toDateString(),
                'status' => FiscalDocument::STATUS_AUTHORIZED,
                'environment' => '2',
                'direction' => FiscalDocument::DIRECTION_RECEIVED,
                'issuer_ruc' => '1758871873001',
                'issuer_name' => 'PROVEEDOR DE PRUEBA S.A.',
                'receiver_ruc' => $company->ruc,
                'receiver_name' => $company->legal_name,
                'subtotal_cents' => 100_000,
                'discount_cents' => 0,
                'tax_cents' => 15_000,
                'ice_cents' => 0,
                'irbpnr_cents' => 0,
                'tip_cents' => 0,
                'total_cents' => 115_000,
                'currency' => 'USD',
                'xml_path' => 'dev/seed.xml',
                'xml_sha256' => str_repeat('a', 64),
                'xml_size' => 100,
                'source' => FiscalDocument::SOURCE_MANUAL_UPLOAD,
                'totals_mismatch' => false,
                'first_seen_at' => now(),
                'updated_or_created_at' => now(),
            ],
        );

        $scenarios = [
            ['description' => 'ALIMENTACION PERSONAL', 'category' => 'alimentacion', 'method' => ClassificationMethod::Llm, 'confidence' => 0.62, 'status' => ClassificationStatus::NeedsReview],
            ['description' => 'GASTOS DE OFICINA', 'category' => 'operativo', 'method' => ClassificationMethod::Rule, 'confidence' => 0.75, 'status' => ClassificationStatus::NeedsReview],
            ['description' => 'SERVICIO DE CONSULTORIA', 'category' => 'operativo', 'method' => ClassificationMethod::Llm, 'confidence' => 0.88, 'status' => ClassificationStatus::Suggested],
            ['description' => 'COMPRA DE EQUIPO DE COMPUTO', 'category' => 'activo_fijo', 'method' => ClassificationMethod::Rule, 'confidence' => 0.97, 'status' => ClassificationStatus::Accepted, 'reviewed' => true],
            ['description' => 'GASTO PERSONAL NO DEDUCIBLE', 'category' => 'no_deducible', 'method' => ClassificationMethod::Llm, 'confidence' => 0.55, 'status' => ClassificationStatus::Rejected, 'reviewed' => true],
            ['description' => 'COMPRA RECURRENTE DE PAPELERIA', 'category' => 'operativo', 'method' => ClassificationMethod::Cache, 'confidence' => 1.0, 'status' => ClassificationStatus::Suggested],
            ['description' => 'GASTO CLASIFICADO MANUALMENTE', 'category' => 'salud', 'method' => ClassificationMethod::Manual, 'confidence' => 1.0, 'status' => ClassificationStatus::Accepted, 'reviewed' => true],
            ['description' => 'CLASIFICACION DE BAJA CONFIANZA', 'category' => 'operativo', 'method' => ClassificationMethod::Llm, 'confidence' => 0.40, 'status' => ClassificationStatus::NeedsReview],
        ];

        foreach ($scenarios as $index => $scenario) {
            $item = FiscalDocumentItem::query()->create([
                'tenant_id' => $tenant->id,
                'fiscal_document_id' => $document->id,
                'line_number' => $index + 1,
                'main_code' => '001',
                'description' => $scenario['description'],
                'normalized_description' => mb_strtoupper($scenario['description']),
                'quantity' => 1,
                'unit_price' => 100,
                'discount' => 0,
                'subtotal_cents' => 10_000,
                'total_cents' => 11_500,
                'classification_status' => 'pending',
            ]);

            $categoryId = (int) ($categories[$scenario['category']] ?? $categories->first());

            Classification::query()->create([
                'tenant_id' => $tenant->id,
                'fiscal_document_item_id' => $item->id,
                'expense_category_id' => $categoryId,
                'method' => $scenario['method']->value,
                'confidence' => $scenario['confidence'],
                'status' => $scenario['status']->value,
                'reviewed_by' => ($scenario['reviewed'] ?? false) ? $owner->getKey() : null,
                'reviewed_at' => ($scenario['reviewed'] ?? false) ? now() : null,
            ]);
        }

        $this->command?->info('ClassificationScenarioSeeder: 8 escenarios de clasificación creados.');
    }
}
