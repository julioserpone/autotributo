<?php

declare(strict_types=1);

namespace App\Providers;

use App\Domain\Audit\Listeners\RecordAuthenticationEvent;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

/**
 * Cablea la auditoría base (F1-07).
 *
 * El driver `database` de `owen-it/laravel-auditing` escribe en `audit_logs`
 * a través de `AuditLog`; aquí solo se enganchan los eventos de autenticación,
 * que no pasan por un modelo auditable.
 */
final class AuditServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Event::listen(Login::class, [RecordAuthenticationEvent::class, 'handleLogin']);
        Event::listen(Logout::class, [RecordAuthenticationEvent::class, 'handleLogout']);
        Event::listen(Failed::class, [RecordAuthenticationEvent::class, 'handleFailed']);
    }
}
