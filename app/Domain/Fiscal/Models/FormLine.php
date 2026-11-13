<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Models;

use App\Domain\Fiscal\Enums\FormLineDataType;
use App\Domain\Fiscal\Models\Concerns\VersionedCatalog;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * Definición versionada de un casillero de formulario del SRI.
 *
 * El mismo `line_code` puede cambiar de etiqueta, sección u obligatoriedad;
 * `valid_from`/`valid_to` permiten recalcular un período con su definición.
 *
 * @property int $id
 * @property string $form_code
 * @property string $line_code
 * @property string $section
 * @property string $label
 * @property FormLineDataType $data_type
 * @property bool $is_required
 * @property int $display_order
 * @property Carbon $valid_from
 * @property Carbon|null $valid_to
 * @property string $source
 * @property Carbon|null $published_at
 * @property string|null $checksum
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class FormLine extends Model
{
    use VersionedCatalog;

    protected $table = 'form_lines';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'data_type' => FormLineDataType::class,
        'is_required' => 'boolean',
        'display_order' => 'integer',
        'valid_from' => 'date',
        'valid_to' => 'date',
        'published_at' => 'date',
    ];
}
