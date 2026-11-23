<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Models;

use App\Domain\Companies\Models\Company;
use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Período fiscal de una empresa (mes/año), con su estado de cierre.
 */
final class FiscalPeriod extends Model
{
    use BelongsToTenant;

    protected $table = 'fiscal_periods';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'opened_at' => 'datetime',
        'locked_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function locker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'locked_by');
    }

    /**
     * @return HasMany<PeriodAggregate, $this>
     */
    public function aggregates(): HasMany
    {
        return $this->hasMany(PeriodAggregate::class, 'fiscal_period_id');
    }
}
