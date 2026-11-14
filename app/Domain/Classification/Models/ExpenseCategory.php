<?php

declare(strict_types=1);

namespace App\Domain\Classification\Models;

use App\Domain\Fiscal\Models\Concerns\VersionedCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * Categoría de gasto (catálogo global, sin tenant).
 *
 * El árbol y las banderas de deducibilidad son datos fiscales: todo cambio
 * debe pasar por `docs/fiscal-signoff.md` antes de usarse en una declaración.
 *
 * @property int $id
 * @property string $code
 * @property string $name
 * @property int|null $parent_id
 * @property bool $is_personal_deductible
 * @property bool $is_business_deductible
 * @property string|null $icon
 * @property int $display_order
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property string $source
 */
final class ExpenseCategory extends Model
{
    use VersionedCatalog;

    protected $table = 'expense_categories';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'parent_id' => 'integer',
        'is_personal_deductible' => 'boolean',
        'is_business_deductible' => 'boolean',
        'display_order' => 'integer',
        'valid_from' => 'date',
        'valid_to' => 'date',
    ];

    /**
     * @return BelongsTo<self, $this>
     */
    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    /**
     * @return HasMany<self, $this>
     */
    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    /**
     * @return HasMany<ClassificationRule, $this>
     */
    public function rules(): HasMany
    {
        return $this->hasMany(ClassificationRule::class);
    }
}
