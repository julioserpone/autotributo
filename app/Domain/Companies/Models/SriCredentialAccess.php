<?php

declare(strict_types=1);

namespace App\Domain\Companies\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;

/**
 * Registro de cada vez que se descifra o se cambia una credencial SRI.
 *
 * Existe para poder responder "¿quién leyó el secreto del contribuyente?" sin
 * guardar el secreto ni el XML. Retención 1 año (docs/03).
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $company_id
 * @property int|null $user_id
 * @property string $purpose
 * @property string|null $ip
 */
final class SriCredentialAccess extends Model
{
    use BelongsToTenant;

    public const PURPOSE_SYNC = 'sync';

    public const PURPOSE_ROTATION = 'rotation';

    public const PURPOSE_SUPPORT = 'support';

    public const UPDATED_AT = null;

    protected $table = 'sri_credential_accesses';

    /** @var list<string> */
    protected $guarded = [];
}
