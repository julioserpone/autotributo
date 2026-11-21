<?php

declare(strict_types=1);

namespace App\Domain\Companies\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Código CIIU del SRI (actividad económica) seleccionable.
 *
 * Solo los nodos hoja del clasificador (último subnivel) son seleccionables,
 * para que el contribuyente quede identificado con el mayor nivel de detalle.
 *
 * @property string $code
 * @property string $description
 * @property string $level
 */
final class EconomicActivityCode extends Model
{
    protected $table = 'economic_activity_codes';

    protected $primaryKey = 'code';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $fillable = ['code', 'description', 'level'];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [];
    }
}
