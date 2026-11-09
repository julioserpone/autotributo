<?php

declare(strict_types=1);

namespace App\Domain\Companies\Models;

use App\Domain\Companies\Enums\TaxRegime;
use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Contribuyente al que pertenecen las credenciales y los comprobantes.
 *
 * El RUC se valida en el FormRequest con el value object `Ruc`; este modelo
 * persiste el perfil tributario y aplica el scoping por tenant.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $ruc
 * @property string $legal_name
 * @property string|null $trade_name
 * @property TaxRegime $regime
 * @property bool $is_accounting_required
 * @property bool $is_withholding_agent
 * @property string|null $withholding_resolution
 * @property string|null $economic_activity_code
 * @property string|null $address
 * @property string|null $email
 * @property string|null $phone
 * @property int $fiscal_year_start_month
 * @property string $status
 * @property array<string, mixed>|null $settings
 * @property int|null $created_by
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 * @property Carbon|null $deleted_at
 */
final class Company extends Model
{
    /** @use HasFactory<CompanyFactory> */
    use BelongsToTenant, HasFactory, SoftDeletes;

    public const STATUS_ACTIVE = 'active';

    public const STATUS_INACTIVE = 'inactive';

    public const STATUS_ARCHIVED = 'archived';

    protected $table = 'companies';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'regime' => TaxRegime::class,
        'is_accounting_required' => 'boolean',
        'is_withholding_agent' => 'boolean',
        'fiscal_year_start_month' => 'integer',
        'settings' => 'array',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $company): void {
            if (blank($company->public_id)) {
                $company->public_id = (string) Str::ulid();
            }
        });
    }

    protected static function newFactory(): CompanyFactory
    {
        return CompanyFactory::new();
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return HasOne<SriCredential, $this>
     */
    public function credential(): HasOne
    {
        return $this->hasOne(SriCredential::class);
    }

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
