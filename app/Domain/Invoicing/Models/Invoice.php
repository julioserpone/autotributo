<?php

declare(strict_types=1);

namespace App\Domain\Invoicing\Models;

use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Models\SriSyncRun;
use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\InvoiceFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Comprobante recibido del SRI, ya normalizado al modelo tributario.
 *
 * La clave de idempotencia es `(tenant_id, company_id, access_key)`: el mismo
 * comprobante puede aparecer en varios runs y debe quedar una sola fila.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $company_id
 * @property SriDocumentType $document_type
 * @property string $access_key
 * @property string $issue_date
 * @property int $subtotal_cents
 * @property int $tax_cents
 * @property int $total_cents
 * @property string $xml_sha256
 * @property bool $totals_mismatch
 * @property Carbon|null $first_seen_at
 * @property Carbon|null $updated_or_created_at
 */
final class Invoice extends Model
{
    /** @use HasFactory<InvoiceFactory> */
    use BelongsToTenant, HasFactory;

    public const SOURCE_SRI_ROBOT = 'sri_robot';

    public const STATUS_AUTHORIZED = 'authorized';

    public const DIRECTION_RECEIVED = 'received';

    protected $table = 'invoices';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'document_type' => SriDocumentType::class,
        'issue_date' => 'date',
        'authorization_date' => 'datetime',
        'first_seen_at' => 'datetime',
        'updated_or_created_at' => 'datetime',
        'totals_mismatch' => 'boolean',
        'raw' => 'array',
        'subtotal_cents' => 'integer',
        'discount_cents' => 'integer',
        'tax_cents' => 'integer',
        'ice_cents' => 'integer',
        'irbpnr_cents' => 'integer',
        'tip_cents' => 'integer',
        'total_cents' => 'integer',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $invoice): void {
            if (blank($invoice->public_id)) {
                $invoice->public_id = (string) Str::ulid();
            }
        });
    }

    /**
     * @return HasMany<InvoiceItem, $this>
     */
    public function items(): HasMany
    {
        return $this->hasMany(InvoiceItem::class);
    }

    /**
     * @return HasMany<InvoiceTax, $this>
     */
    public function taxes(): HasMany
    {
        return $this->hasMany(InvoiceTax::class);
    }

    /**
     * @return HasMany<RetentionLine, $this>
     */
    public function retentions(): HasMany
    {
        return $this->hasMany(RetentionLine::class);
    }

    /**
     * @return BelongsTo<SriSyncRun, $this>
     */
    public function syncRun(): BelongsTo
    {
        return $this->belongsTo(SriSyncRun::class, 'sync_run_id');
    }
}
