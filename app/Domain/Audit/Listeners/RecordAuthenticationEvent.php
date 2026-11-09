<?php

declare(strict_types=1);

namespace App\Domain\Audit\Listeners;

use App\Domain\Audit\Actions\RecordAudit;
use App\Models\User;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;

/**
 * Audita los eventos de autenticación que no pasan por un modelo.
 *
 * Un login fallido se registra sin `user_id` (no hay usuario autenticado) y
 * sin el email en claro: solo el identificador del intento, para no acumular
 * PII en el historial.
 */
final readonly class RecordAuthenticationEvent
{
    public function __construct(private RecordAudit $record) {}

    public function handleLogin(Login $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->record->handle(
            event: 'login',
            auditable: $event->user,
            newValues: ['guard' => $event->guard, 'remember' => $event->remember],
            userId: $event->user->getKey(),
        );
    }

    public function handleLogout(Logout $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->record->handle(
            event: 'logout',
            auditable: $event->user,
            newValues: ['guard' => $event->guard],
            userId: $event->user->getKey(),
        );
    }

    public function handleFailed(Failed $event): void
    {
        if (! $event->user instanceof User) {
            return;
        }

        $this->record->handle(
            event: 'login_failed',
            auditable: $event->user,
            newValues: ['guard' => $event->guard],
            userId: $event->user->getKey(),
        );
    }
}
