<?php

declare(strict_types=1);

namespace App\Http\Controllers\Api\V1;

use App\Http\Controllers\Controller;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Broadcast;

/**
 * Autoriza la suscripción a canales privados de Reverb (F1-08).
 *
 * Se expone bajo `/api/v1` para que la SPA use la misma sesión, CSRF y
 * contexto de tenant que el resto de la API. La regla de pertenencia vive en
 * `routes/channels.php`; aquí solo se serializa el payload de autorización.
 */
final class BroadcastingAuthController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        return response()->json(Broadcast::auth($request));
    }
}
