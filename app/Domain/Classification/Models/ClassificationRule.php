<?php

declare(strict_types=1);

namespace App\Domain\Classification\Models;

use App\Domain\Classification\Enums\MatchType;
use App\Domain\Classification\Models\Concerns\BelongsToTenantOrGlobal;
use App\Domain\Classification\Services\ClassificationRuleRepository;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/**
 * Regla de clasificación automática por descripción, proveedor o código.
 *
 * `tenant_id` nulo = regla global del catálogo base. La precedencia es
 * `priority` ascendente y, en empate, la regla del tenant gana a la global.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property string $name
 * @property int $priority
 * @property MatchType $match_type
 * @property string $pattern
 * @property int $expense_category_id
 * @property bool $is_active
 * @property int|null $created_by
 * @property int $hits_count
 * @property Carbon|null $last_matched_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class ClassificationRule extends Model
{
    use BelongsToTenantOrGlobal, SoftDeletes;

    protected $table = 'classification_rules';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'priority' => 'integer',
        'match_type' => MatchType::class,
        'expense_category_id' => 'integer',
        'is_active' => 'boolean',
        'created_by' => 'integer',
        'hits_count' => 'integer',
        'last_matched_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        // Cualquier cambio en las reglas invalida el catálogo cacheado que usa
        // el motor: es pequeño y se recalcula en la siguiente clasificación.
        $flush = static function (): void {
            ClassificationRuleRepository::flush();
        };

        self::saved($flush);
        self::deleted($flush);
        self::restored($flush);
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @return HasMany<Classification, $this>
     */
    public function classifications(): HasMany
    {
        return $this->hasMany(Classification::class, 'rule_id');
    }

    public function isGlobal(): bool
    {
        return $this->tenant_id === null;
    }
}
