<?php

declare(strict_types=1);

namespace App\Domain\Tenancy\Models;

use Database\Factories\TenantFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use Stancl\Tenancy\Contracts\Tenant as TenantContract;

/**
 * Estudio contable. En el MVP single-tenant existe una sola fila; en modo
 * multi cada fila representa un estudio y todas las tablas de negocio
 * apuntan a su `id` mediante `tenant_id`.
 *
 * @property int $id
 * @property string $public_id
 * @property string $name
 * @property string $slug
 * @property string $status
 * @property string $plan
 * @property array<string, mixed>|null $settings
 * @property Carbon|null $trial_ends_at
 */
final class Tenant extends Model implements TenantContract
{
    /** @use HasFactory<TenantFactory> */
    use HasFactory;

    public const STATUS_TRIAL = 'trial';

    public const STATUS_ACTIVE = 'active';

    public const STATUS_SUSPENDED = 'suspended';

    public const STATUS_CANCELLED = 'cancelled';

    protected $table = 'tenants';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'settings' => 'array',
        'trial_ends_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $tenant): void {
            if (blank($tenant->public_id)) {
                $tenant->public_id = (string) Str::ulid();
            }
        });
    }

    protected static function newFactory(): TenantFactory
    {
        return TenantFactory::new();
    }

    public function getTenantKeyName(): string
    {
        return $this->getKeyName();
    }

    public function getTenantKey(): int
    {
        return (int) $this->getKey();
    }

    public function getInternal(string $key): mixed
    {
        /** @var array<string, mixed> $internal */
        $internal = $this->settings['_internal'] ?? [];

        return $internal[$key] ?? null;
    }

    public function setInternal(string $key, mixed $value): static
    {
        $settings = $this->settings ?? [];
        $settings['_internal'][$key] = $value;
        $this->settings = $settings;

        return $this;
    }

    /**
     * Ejecuta un callback en el contexto de este tenant y restaura el
     * contexto anterior incluso si el callback lanza una excepción.
     */
    public function run(callable $callback): mixed
    {
        $previous = tenancy()->tenant;

        tenancy()->initialize($this);

        try {
            return $callback($this);
        } finally {
            if ($previous instanceof TenantContract) {
                tenancy()->initialize($previous);
            } else {
                tenancy()->end();
            }
        }
    }

    /**
     * @return HasMany<Domain, $this>
     */
    public function domains(): HasMany
    {
        return $this->hasMany(Domain::class);
    }

    public function isActive(): bool
    {
        return in_array($this->status, [self::STATUS_TRIAL, self::STATUS_ACTIVE], true);
    }
}
