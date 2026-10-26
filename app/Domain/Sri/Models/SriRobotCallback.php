<?php

declare(strict_types=1);

namespace App\Domain\Sri\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * Callback firmado recibido del robot.
 *
 * La tabla no lleva `tenant_id`: el evento se identifica por `event_id` y se
 * correlaciona con el run cuando el robot reporta un evento que la app generó.
 * Es la fuente de verdad para la deduplicación del contrato F2-04.
 *
 * @property int $id
 * @property string $event_id
 * @property int|null $run_id
 * @property string $status
 * @property string|null $request_id
 * @property string|null $ruc
 * @property list<string>|null $periods
 * @property array<string, mixed> $payload
 * @property int $signature_timestamp
 * @property Carbon $received_at
 */
final class SriRobotCallback extends Model
{
    protected $table = 'sri_robot_callbacks';

    /** @var list<string> */
    protected $guarded = [];

    /** @var array<string, string> */
    protected $casts = [
        'periods' => 'array',
        'payload' => 'array',
        'signature_timestamp' => 'integer',
        'received_at' => 'datetime',
    ];

    /**
     * @return BelongsTo<SriSyncRun, $this>
     */
    public function run(): BelongsTo
    {
        return $this->belongsTo(SriSyncRun::class, 'run_id');
    }
}
