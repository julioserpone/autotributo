<?php

declare(strict_types=1);

namespace App\Domain\Sri\Models;

use App\Domain\Sri\Enums\SriDocumentType;
use App\Domain\Sri\Enums\SriSyncRunFileStatus;
use App\Domain\Tenancy\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Objeto del robot (manifiesto, TXT o JSON de claves) asociado a un run.
 *
 * La reconciliación hace `updateOrCreate` por `(run_id, s3_key)`; repetir el
 * proceso no duplica archivos ni pierde el checksum ya calculado.
 *
 * @property int $id
 * @property int $tenant_id
 * @property int $run_id
 * @property string $file_name
 * @property string $s3_key
 * @property string|null $checksum
 * @property SriDocumentType|null $document_type
 * @property SriSyncRunFileStatus $status
 * @property string|null $error_message
 * @property Carbon|null $processed_at
 */
final class SriSyncRunFile extends Model
{
    use BelongsToTenant;

    protected $table = 'sri_sync_run_files';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'document_type' => SriDocumentType::class,
        'status' => SriSyncRunFileStatus::class,
        'processed_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<SriSyncRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SriSyncRun::class, 'run_id');
    }

    public function isSuccessful(): bool
    {
        return $this->status->isSuccessful();
    }
}
