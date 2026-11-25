<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Models;

use App\Domain\Companies\Models\Company;
use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * Período fiscal de una empresa (mes/año), con su estado de cierre.
 *
 * `aggregation_status` registra el último intento de recálculo de
 * `period_aggregates` (F5-07a): un período con `failed` no es un período sin
 * comprobantes.
 */
final class FiscalPeriod extends Model
{
    use BelongsToTenant;

    public const AGGREGATION_PENDING = 'pending';

    public const AGGREGATION_COMPLETED = 'completed';

    public const AGGREGATION_FAILED = 'failed';

    protected $table = 'fiscal_periods';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'year' => 'integer',
        'month' => 'integer',
        'opened_at' => 'datetime',
        'locked_at' => 'datetime',
        'aggregation_attempts' => 'integer',
        'aggregated_at' => 'datetime',
    ];

    /**
     * Marca el período como agregado con éxito.
     */
    public function markAggregated(): void
    {
        $this->forceFill([
            'aggregation_status' => self::AGGREGATION_COMPLETED,
            'aggregation_error' => null,
            'aggregation_attempts' => ((int) $this->aggregation_attempts) + 1,
            'aggregated_at' => now(),
        ])->save();
    }

    /**
     * Marca el período como fallido conservando el motivo para la UI.
     */
    public function markAggregationFailed(string $reason): void
    {
        $this->forceFill([
            'aggregation_status' => self::AGGREGATION_FAILED,
            'aggregation_error' => Str::limit($reason, 480),
            'aggregation_attempts' => ((int) $this->aggregation_attempts) + 1,
        ])->save();
    }

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
