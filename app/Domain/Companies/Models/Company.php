<?php

declare(strict_types=1);

namespace App\Domain\Companies\Models;

use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use Database\Factories\CompanyFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/**
 * Contribuyente al que pertenecen las credenciales y los comprobantes.
 *
 * En esta fase se incorpora la tabla mínima que necesitan los runs de
 * sincronización; el CRUD completo y las validaciones de RUC llegan en F1-05.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property string $ruc
 * @property string $legal_name
 * @property string $status
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

    public function isActive(): bool
    {
        return $this->status === self::STATUS_ACTIVE;
    }
}
