<?php

declare(strict_types=1);

namespace App\Domain\Audit\Models;

use App\Domain\Tenancy\Models\Tenant;
use App\Domain\Tenancy\TenantContext;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use OwenIt\Auditing\Models\Audit;

/**
 * Registro de auditoría de la aplicación.
 *
 * Extiende el modelo del paquete para añadir `tenant_id` (el paquete no lo
 * conoce) y para redactar valores sensibles antes de persistir. La tabla es
 * `audit_logs` (docs/03 §9), no `audits`.
 *
 * @property int $id
 * @property int|null $tenant_id
 * @property int|null $user_id
 * @property string $event
 * @property string $auditable_type
 * @property int $auditable_id
 * @property array<string, mixed>|null $old_values
 * @property array<string, mixed>|null $new_values
 * @property string|null $ip_address
 * @property string|null $user_agent
 * @property string|null $url
 * @property string|null $request_id
 */
final class AuditLog extends Audit
{
    /**
     * Atributos que nunca deben quedar en el historial, ni siquiera cifrados.
     *
     * @var list<string>
     */
    public const REDACTED_ATTRIBUTES = [
        'password',
        'password_confirmation',
        'password_ciphertext',
        'remember_token',
        'two_factor_secret',
        'two_factor_recovery_codes',
        'token_hash',
    ];

    protected $table = 'audit_logs';

    /**
     * La tabla solo tiene `created_at`: un registro de auditoría es inmutable.
     */
    public const UPDATED_AT = null;

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'old_values' => 'array',
        'new_values' => 'array',
        'tags' => 'array',
        'created_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $log): void {
            $log->tenant_id ??= self::currentTenantId();
            $log->old_values = self::redact($log->old_values);
            $log->new_values = self::redact($log->new_values);
        });
    }

    /**
     * @return BelongsTo<Tenant, $this>
     */
    public function tenant(): BelongsTo
    {
        /** @var class-string<Tenant> $tenantModel */
        $tenantModel = config('tenancy.tenant_model');

        return $this->belongsTo($tenantModel);
    }

    /**
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public static function redact(?array $values): ?array
    {
        if ($values === null) {
            return null;
        }

        foreach (self::REDACTED_ATTRIBUTES as $attribute) {
            if (array_key_exists($attribute, $values)) {
                $values[$attribute] = '***';
            }
        }

        return $values;
    }

    private static function currentTenantId(): ?int
    {
        try {
            return app(TenantContext::class)->id();
        } catch (\Throwable) {
            return null;
        }
    }
}
