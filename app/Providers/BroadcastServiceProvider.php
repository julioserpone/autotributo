<?php

declare(strict_types=1);

namespace App\Providers;

use Illuminate\Broadcasting\BroadcastManager;
use Illuminate\Support\ServiceProvider;

/**
 * Registra los canales privados de Reverb (F1-08).
 *
 * `Broadcast::channel()` resuelve el driver en el momento de la llamada. Si la
 * configuración cambia al arrancar (tests con `BROADCAST_CONNECTION` propio,
 * entornos sin Reverb), los canales quedarían en una instancia abandonada del
 * manager. Purgar antes de cargar deja los canales en el driver que realmente
 * se usa.
 */
final class BroadcastServiceProvider extends ServiceProvider
{
    public function boot(BroadcastManager $broadcaster): void
    {
        $broadcaster->purge();

        require base_path('routes/channels.php');
    }
}
