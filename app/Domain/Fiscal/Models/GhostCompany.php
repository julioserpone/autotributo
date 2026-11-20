<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Empresa fantasma publicada por el SRI.
 *
 * Catálogo global (sin tenant): se importa desde la fuente oficial mediante
 * el comando `ghost-companies:import` y se refresca periódicamente.
 */
final class GhostCompany extends Model
{
    protected $table = 'ghost_companies';

    protected $primaryKey = 'ruc';

    public $incrementing = false;

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'published_at' => 'date',
        'imported_at' => 'datetime',
    ];
}