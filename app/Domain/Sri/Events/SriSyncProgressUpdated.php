<?php

declare(strict_types=1);

namespace App\Domain\Sri\Events;

use App\Domain\Sri\Models\SriSyncRun;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Notificación de progreso de una sincronización (F2-09b).
 *
 * Es una notificación, no fuente de verdad: el cliente siempre reconcilia
 * con el polling (F2-09a). Se emite en el canal privado de la empresa.
 */
final class SriSyncProgressUpdated implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public readonly SriSyncRun $run) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $run = $this->run->loadMissing('company');

        return [
            new PrivateChannel("tenant.{$run->tenant_id}.company.{$run->company?->public_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SriSyncProgressUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $run = $this->run;

        return [
            'run_id' => $run->public_id,
            'status' => $run->status->value,
            'is_active' => $run->isActive(),
            'poll_after_ms' => $run->isActive() ? 5000 : null,
            'progress' => $run->progressPercentage(),
            'totals' => $run->totals ?? [
                'found' => 0,
                'imported' => 0,
                'skipped' => 0,
                'failed' => 0,
            ],
        ];
    }
}
