<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Companies\Models\Company;
use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Company>
 */
final class CompanyFactory extends Factory
{
    protected $model = Company::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'tenant_id' => Tenant::factory(),
            'ruc' => fake()->unique()->numerify('#############'),
            'legal_name' => fake()->company(),
            'trade_name' => fake()->optional()->company(),
            'regime' => 'general',
            'is_accounting_required' => true,
            'is_withholding_agent' => false,
            'fiscal_year_start_month' => 1,
            'status' => Company::STATUS_ACTIVE,
            'settings' => [],
        ];
    }
}
