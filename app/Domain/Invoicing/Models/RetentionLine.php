<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Retención practicada en un comprobante de retención (tipo 07).
 */
final class RetentionLine extends Model
{
    use BelongsToTenant;

    protected $table = 'retention_lines';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'base_cents' => 'integer',
        'amount_cents' => 'integer',
    ];

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
