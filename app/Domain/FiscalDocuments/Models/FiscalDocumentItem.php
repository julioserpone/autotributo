<?php

declare(strict_types=1);

namespace App\Domain\FiscalDocuments\Models;

use App\Domain\Classification\Models\Classification;
use App\Domain\Classification\Models\ExpenseCategory;
use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;

/**
 * Línea de detalle de un comprobante.
 *
 * `normalized_description` es la clave de las reglas de clasificación y de la
 * caché de IA; `classification_status` empieza en `pending` hasta F3.
 */
final class FiscalDocumentItem extends Model
{
    use BelongsToTenant;

    public const STATUS_PENDING = 'pending';

    public const STATUS_AUTO = 'auto';

    public const STATUS_REVIEW = 'review';

    public const STATUS_MANUAL = 'manual';

    protected $table = 'fiscal_document_items';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'line_number' => 'integer',
        'subtotal_cents' => 'integer',
        'total_cents' => 'integer',
    ];

    /**
     * @return BelongsTo<FiscalDocument, $this>
     */
    public function fiscal_document(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    /**
     * @return HasOne<Classification, $this>
     */
    public function classification(): HasOne
    {
        return $this->hasOne(Classification::class, 'fiscal_document_item_id');
    }
}
