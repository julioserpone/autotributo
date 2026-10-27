<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Línea de detalle de un comprobante.
 *
 * `normalized_description` es la clave de las reglas de clasificación y de la
 * caché de IA; `classification_status` empieza en `pending` hasta F3.
 */
final class InvoiceItem extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    protected $table = 'invoice_items';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'line_number' => 'integer',
        'subtotal_cents' => 'integer',
        'total_cents' => 'integer',
    ];

    /**
     * @return BelongsTo<Invoice, $this>
     */
    public function invoice(): BelongsTo
    {
        return $this->belongsTo(Invoice::class);
    }
}
