<?php

declare(strict_types=1);

namespace Database\Factories;

use App\Domain\Tenancy\Models\Tenant;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<Tenant>
 */
final class TenantFactory extends Factory
{
    protected $model = Tenant::class;

    /**
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        $name = fake()->unique()->company();

        return [
            'public_id' => (string) Str::ulid(),
            'name' => $name,
            'slug' => Str::slug($name).'-'.fake()->unique()->numerify('####'),
            'status' => Tenant::STATUS_ACTIVE,
            'plan' => 'pro',
            'settings' => [],
            'trial_ends_at' => null,
        ];
    }

    public function suspended(): static
    {
        return $this->state(fn (): array => [
            'status' => Tenant::STATUS_SUSPENDED,
        ]);
    }

    public function trial(): static
    {
        return $this->state(fn (): array => [
            'status' => Tenant::STATUS_TRIAL,
            'trial_ends_at' => now()->addDays(14),
        ]);
    }
}
