<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Models;

use App\Domain\Companies\Models\Company;
use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Agregado materializado por período, empresa y dimensión (dirección,
 * tipo de documento, código de impuesto y porcentaje SRI).
 */
final class PeriodAggregate extends Model
{
    use BelongsToTenant;

    protected $table = 'period_aggregates';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'rate' => 'decimal:6',
        'base_cents' => 'integer',
        'tax_cents' => 'integer',
        'retention_cents' => 'integer',
        'documents_count' => 'integer',
        'version' => 'integer',
        'computed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<FiscalPeriod, $this>
     */
    public function period(): BelongsTo
    {
        return $this->belongsTo(FiscalPeriod::class, 'fiscal_period_id');
    }
}
