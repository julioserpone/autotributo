<?php

declare(strict_types=1);

namespace App\Domain\Classification\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Classification\Enums\ClassificationMethod;
use App\Domain\Classification\Enums\ClassificationStatus;
use App\Domain\Invoicing\Models\InvoiceItem;
use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Clasificación vigente de un ítem de comprobante.
 *
 * Guarda la procedencia (`method`, `rule_id`) para poder explicar y auditar
 * por qué un gasto quedó en una categoría. La revisión humana de F3-05 cambia
 * `status` y `reviewed_by`, nunca reescribe el origen.
 *
 * @property int $id
 * @property int $invoice_item_id
 * @property int $expense_category_id
 * @property ClassificationMethod $method
 * @property float $confidence
 * @property ClassificationStatus $status
 * @property int|null $rule_id
 * @property int|null $reviewed_by
 * @property Carbon|null $reviewed_at
 */
final class Classification extends Model implements AuditableContract
{
    use Auditable, BelongsToTenant;

    protected $table = 'classifications';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'invoice_item_id' => 'integer',
        'expense_category_id' => 'integer',
        'method' => ClassificationMethod::class,
        'confidence' => 'float',
        'status' => ClassificationStatus::class,
        'rule_id' => 'integer',
        'reviewed_by' => 'integer',
        'reviewed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<InvoiceItem, $this>
     */
    public function invoiceItem(): BelongsTo
    {
        return $this->belongsTo(InvoiceItem::class);
    }

    /**
     * @return BelongsTo<ExpenseCategory, $this>
     */
    public function expenseCategory(): BelongsTo
    {
        return $this->belongsTo(ExpenseCategory::class);
    }

    /**
     * @return BelongsTo<ClassificationRule, $this>
     */
    public function rule(): BelongsTo
    {
        return $this->belongsTo(ClassificationRule::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function reviewer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reviewed_by');
    }
}
