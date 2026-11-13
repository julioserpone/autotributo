<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Models;

use App\Domain\Fiscal\Enums\RetentionTaxType;
use App\Domain\Fiscal\Models\Concerns\VersionedCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Porcentaje y código de retención en la fuente (IR o IVA).
 *
 * Un mismo código puede tener varias filas si cambia su porcentaje: la
 * vigencia decide cuál aplicar al comprobante.
 *
 * @property int $id
 * @property RetentionTaxType $tax_type
 * @property string $code
 * @property string $rate
 * @property string $label
 * @property string|null $applies_to
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property string|null $legal_basis
 * @property string $source
 * @property Carbon|null $published_at
 * @property string|null $checksum
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class RetentionRate extends Model
{
    use VersionedCatalog;

    protected $table = 'retention_rates';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'tax_type' => RetentionTaxType::class,
        'rate' => 'decimal:6',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'published_at' => 'date',
    ];
}
