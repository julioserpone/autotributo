<?php

declare(strict_types=1);

namespace App\Domain\Fiscal\Models;

use App\Domain\Companies\Models\Company;
use App\Domain\Fiscal\Enums\FiscalRiskCode;
use App\Domain\Fiscal\Enums\FiscalRiskSeverity;
use App\Domain\Fiscal\Enums\FiscalRiskStatus;
use App\Domain\FiscalDocuments\Models\FiscalDocument;
use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use App\Models\User;
use Database\Factories\FiscalFindingFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Hallazgo fiscal detectado sobre un comprobante o conjunto de comprobantes.
 *
 * El estado (`open/acknowledged/resolved/dismissed`) permite que el contador
 * gestione los hallazgos sin perder la trazabilidad del origen (`source_type`,
 * `source_id`). El índice único parcial evita duplicar hallazgos abiertos con
 * la misma procedencia.
 */
final class FiscalFinding extends Model
{
    /** @use HasFactory<FiscalFindingFactory> */
    use BelongsToTenant, HasFactory;

    protected static function newFactory(): FiscalFindingFactory
    {
        return FiscalFindingFactory::new();
    }

    protected $table = 'fiscal_findings';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'code' => FiscalRiskCode::class,
        'severity' => FiscalRiskSeverity::class,
        'status' => FiscalRiskStatus::class,
        'metadata' => 'array',
        'resolved_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<Company, $this>
     */
    public function company(): BelongsTo
    {
        return $this->belongsTo(Company::class);
    }

    /**
     * @return BelongsTo<User, $this>
     */
    public function resolver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'resolved_by');
    }

    /**
     * Resuelve el comprobante de origen cuando `source_type = fiscal_document`.
     */
    public function sourceDocument(): ?FiscalDocument
    {
        if ($this->source_type !== 'fiscal_document') {
            return null;
        }

        return FiscalDocument::query()->find($this->source_id);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeOpen($query)
    {
        return $query->where('status', FiscalRiskStatus::Open->value);
    }

    /**
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     * @return \Illuminate\Database\Eloquent\Builder<static>
     */
    public function scopeForCompany($query, int $companyId)
    {
        return $query->where('company_id', $companyId);
    }
}