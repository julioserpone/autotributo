<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Models;

use App\Domain\Fiscal\Models\Concerns\VersionedCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Tarifa de IVA versionada por fecha de vigencia.
 *
 * `sri_percentage_code` es el código que viaja en el XML del SRI
 * (`codigoPorcentaje`) y no debe confundirse con la tarifa.
 *
 * @property int $id
 * @property string $code
 * @property string $sri_percentage_code
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
final class TaxRate extends Model
{
    use VersionedCatalog;

    protected $table = 'tax_rates';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'rate' => 'decimal:6',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'published_at' => 'date',
    ];
}
