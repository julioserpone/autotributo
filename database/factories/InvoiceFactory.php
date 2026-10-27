<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Companies\Models\Company;
use App\Domain\Invoicing\Models\Invoice;
use App\Domain\Sri\Enums\SriDocumentType;
use Illuminate\Database\Eloquent\Factories\Factory;
use JulioSerpone\SriComprobantes\AccessKey\AccessKeyGenerator;

/**
 * @extends Factory<Invoice>
 */
final class InvoiceFactory extends Factory
{
    protected $model = Invoice::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $subtotal = 10_000;

        return [
            'company_id' => Company::factory(),
            'document_type' => SriDocumentType::Invoice,
            'access_key' => $this->fakeAccessKey(),
            'establishment' => '001',
            'emission_point' => '001',
            'sequential' => '000000123',
            'issue_date' => '2026-09-15',
            'status' => Invoice::STATUS_AUTHORIZED,
            'environment' => '2',
            'direction' => Invoice::DIRECTION_RECEIVED,
            'issuer_ruc' => '1758871873001',
            'issuer_name' => 'PROVEEDOR DE PRUEBA S.A.',
            'receiver_ruc' => '1790012345001',
            'receiver_name' => 'CLIENTE DE PRUEBA CIA. LTDA.',
            'subtotal_cents' => $subtotal,
            'discount_cents' => 0,
            'tax_cents' => 1_500,
            'ice_cents' => 0,
            'irbpnr_cents' => 0,
            'tip_cents' => 0,
            'total_cents' => $subtotal + 1_500,
            'currency' => 'USD',
            'xml_path' => '2/1758871873001/2026-09/'.$this->fakeAccessKey().'.xml',
            'xml_sha256' => str_repeat('a', 64),
            'xml_size' => 100,
            'source' => Invoice::SOURCE_SRI_ROBOT,
            'totals_mismatch' => false,
            'first_seen_at' => now(),
            'updated_or_created_at' => now(),
        ];
    }

    /**
     * Clave de acceso de 49 dígitos con secuencial aleatorio y verificador
     * calculado por el paquete.
     */
    private function fakeAccessKey(): string
    {
        $body = '15092026'.'01'.'1758871873001'.'2'.'001001'
            .str_pad((string) random_int(1, 999_999_999), 9, '0', STR_PAD_LEFT)
            .str_pad((string) random_int(1, 99_999_999), 8, '0', STR_PAD_LEFT)
            .'1';

        return $body.AccessKeyGenerator::module11($body);
    }
}
