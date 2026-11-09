<?php

declare(strict_types=1);

namespace App\Domain\Companies\Models;

use App\Domain\Audit\Concerns\Auditable;
use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use OwenIt\Auditing\Contracts\Auditable as AuditableContract;

/**
 * Credencial del portal del SRI para un contribuyente.
 *
 * El secreto vive cifrado en `password_ciphertext` y solo se descifra a través
 * de `ReadSriCredential`, que además registra el acceso. El modelo nunca
 * serializa el blob: `$hidden` + `__debugInfo` redactado son defensa en
 * profundidad contra un `json_encode` o un `dump` accidental.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $company_id
 * @property string $username
 * @property string $password_ciphertext
 * @property int $key_version
 * @property string $status
 * @property int|null $created_by
 * @property Carbon|null $last_used_at
 * @property Carbon|null $last_success_at
 * @property Carbon|null $last_failure_at
 * @property int $failure_count
 * @property Carbon|null $rotated_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
final class SriCredential extends Model implements AuditableContract
{
    use Auditable, BelongsToTenant;

    /**
     * El blob cifrado nunca entra al historial: la auditoría registra que la
     * credencial cambió, no su contenido.
     *
     * @var list<string>
     */
    protected $auditExclude = ['password_ciphertext'];

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INVALID = 'invalid';

    public const STATUS_LOCKED = 'locked';

    public const STATUS_REVOKED = 'revoked';

    protected $table = 'sri_credentials';

    /** @var list<string> */
    protected $guarded = [];

    /** @var list<string> */
    protected $hidden = ['password_ciphertext'];

    /** @var array<string, string> */
    protected $casts = [
        'key_version' => 'integer',
        'failure_count' => 'integer',
        'last_used_at' => 'datetime',
        'last_success_at' => 'datetime',
        'last_failure_at' => 'datetime',
        'rotated_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $credential): void {
            if (blank($credential->public_id)) {
                $credential->public_id = (string) Str::ulid();
            }
        });
    }

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }

    /**
     * @return array<string, mixed>
     */
    public function __debugInfo(): array
    {
        $attributes = $this->getAttributes();
        $attributes['password_ciphertext'] = '***';

        return $attributes;
    }
}
