<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Modelo mínimo para probar el scope y el trait sin depender de una tabla
 * de negocio concreta. La tabla se crea en el propio test.
 */
final class TenantScopedRecord extends Model
{
    use BelongsToTenant;

    protected $table = 'tenant_scoped_records';

    /** @var list<string> */
    protected $guarded = [];

    public $timestamps = false;

    /** @var array<string, string> */
    protected $casts = [
        'tenant_id' => 'integer',
    ];
}
