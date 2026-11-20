<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Enums\FiscalRiskCode;
use App\Domain\Fiscal\Enums\FiscalRiskSeverity;
use App\Domain\Fiscal\Enums\FiscalRiskStatus;
use App\Domain\Fiscal\Models\FiscalFinding;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<FiscalFinding>
 */
final class FiscalFindingFactory extends Factory
{
    protected $model = FiscalFinding::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'company_id' => Company::factory(),
            'code' => FiscalRiskCode::TotalsMismatch->value,
            'severity' => FiscalRiskSeverity::Critical->value,
            'title' => 'Totales inconsistentes',
            'description' => 'El total del comprobante no coincide con la suma de sus componentes.',
            'source_type' => 'fiscal_document',
            'source_id' => 1,
            'metadata' => null,
            'status' => FiscalRiskStatus::Open->value,
            'resolved_by' => null,
            'resolved_at' => null,
        ];
    }
}
