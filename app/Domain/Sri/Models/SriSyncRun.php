<?php

declare(strict_types=1);

namespace App\Domain\Sri\Models;

use App\Domain\Companies\Models\Company;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Ejecución de una descarga del robot para una empresa y un rango de fechas.
 *
 * El estado se mueve con transiciones explícitas; un run final no se reabre
 * por un callback o reintento tardío.
 *
 * @property int $id
 * @property string $public_id
 * @property int $tenant_id
 * @property int $company_id
 * @property int|null $requested_by
 * @property SriSyncRunStatus $status
 * @property Carbon $date_from
 * @property Carbon $date_to
 * @property list<string> $document_types
 * @property string $idempotency_key
 * @property string|null $lambda_request_id
 * @property string|null $s3_prefix
 * @property array<string, int>|null $totals
 * @property string|null $error_code
 * @property string|null $error_message
 * @property Carbon|null $started_at
 * @property Carbon|null $finished_at
 */
final class SriSyncRun extends Model
{
    use BelongsToTenant;

    protected $table = 'sri_sync_runs';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'status' => SriSyncRunStatus::class,
        'date_from' => 'date',
        'date_to' => 'date',
        'document_types' => 'array',
        'totals' => 'array',
        'started_at' => 'datetime',
        'finished_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        self::creating(function (self $run): void {
            if (blank($run->public_id)) {
                $run->public_id = (string) Str::ulid();
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

    /**
     * @return BelongsTo<User, $this>
     */
    public function requestedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function isActive(): bool
    {
        return $this->status->isActive();
    }

    public function transitionTo(SriSyncRunStatus $next): void
    {
        if (! $this->status->canTransitionTo($next)) {
            throw new InvalidArgumentException(
                "Transición de sincronización no permitida: {$this->status->value} → {$next->value}.",
            );
        }

        $this->status = $next;

        if ($next->isFinal()) {
            $this->finished_at ??= now();
        }

        $this->save();
    }

    public function markInvoking(?string $requestId): void
    {
        $this->lambda_request_id = $requestId;
        $this->started_at ??= now();
        $this->transitionTo(SriSyncRunStatus::Invoking);
    }

    public function markFailed(string $code, string $message): void
    {
        if ($this->status->isFinal()) {
            return;
        }

        $this->error_code = $code;
        $this->error_message = $message;
        $this->transitionTo(SriSyncRunStatus::Failed);
    }

    public function cancel(): bool
    {
        if ($this->status->isFinal()) {
            return false;
        }

        $this->transitionTo(SriSyncRunStatus::Cancelled);

        return true;
    }
}
