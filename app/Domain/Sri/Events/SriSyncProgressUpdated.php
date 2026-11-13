<?php

declare(strict_types=1);

namespace App\Domain\Sri\Events;

use App\Domain\Companies\Models\Company;
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

    /** Caché del `public_id`; el evento se serializa y puede reutilizarlo. */
    private ?string $companyPublicId = null;

    public function __construct(public readonly SriSyncRun $run) {}

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("tenant.{$this->run->tenant_id}.company.{$this->companyPublicId()}"),
        ];
    }

    /**
     * Resuelve el `public_id` de la empresa sin depender del contexto de tenant.
     *
     * El evento también se emite desde el webhook del robot, que se autentica
     * por HMAC y no abre contexto; usar el `TenantScope` ahí reventaría el
     * worker (`TenantContextNotSet`) y el progreso nunca llegaría al monitor.
     */
    private function companyPublicId(): string
    {
        if ($this->companyPublicId !== null) {
            return $this->companyPublicId;
        }

        if ($this->run->relationLoaded('company') && $this->run->company !== null) {
            return $this->companyPublicId = $this->run->company->public_id;
        }

        return $this->companyPublicId = (string) Company::query()
            ->withoutGlobalScopes()
            ->whereKey($this->run->company_id)
            ->value('public_id');
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
