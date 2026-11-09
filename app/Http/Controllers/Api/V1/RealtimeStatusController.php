<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;

/**
 * Estado de la conexión en tiempo real (F1-08).
 *
 * El frontend lo usa para decidir si muestra el indicador de Reverb o si se
 * queda solo con el polling de respaldo (F2-09a). No expone secretos: la
 * `key` de Pusher/Reverb es pública por diseño.
 */
final class RealtimeStatusController extends Controller
{
    public function __invoke(): JsonResponse
    {
        $driver = (string) config('broadcasting.default');
        $enabled = $driver === 'reverb' && (string) config('broadcasting.connections.reverb.key', '') !== '';

        return response()->json([
            'data' => [
                'enabled' => $enabled,
                'driver' => $driver,
                'key' => $enabled ? config('broadcasting.connections.reverb.key') : null,
                'host' => $enabled ? config('broadcasting.connections.reverb.options.host') : null,
                'port' => $enabled ? (int) config('broadcasting.connections.reverb.options.port') : null,
                'scheme' => $enabled ? config('broadcasting.connections.reverb.options.scheme') : null,
            ],
        ]);
    }
}
