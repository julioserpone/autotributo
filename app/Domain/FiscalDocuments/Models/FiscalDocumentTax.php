<?php

declare(strict_types=1);

namespace App\Domain\FiscalDocuments\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Impuesto de cabecera de un comprobante.
 */
final class FiscalDocumentTax extends Model
{
    use BelongsToTenant;

    protected $table = 'fiscal_document_taxes';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'base_cents' => 'integer',
        'amount_cents' => 'integer',
    ];

    /**
     * @return BelongsTo<FiscalDocument, $this>
     */
    public function fiscal_document(): BelongsTo
    {
        return $this->belongsTo(FiscalDocument::class);
    }
}
