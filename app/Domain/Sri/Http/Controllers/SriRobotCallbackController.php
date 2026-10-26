<?php

declare(strict_types=1);

namespace App\Domain\Sri\Http\Controllers;

use App\Domain\Sri\Data\SriRobotCallback;
use App\Domain\Sri\Enums\SriSyncRunStatus;
use App\Domain\Sri\Http\Middleware\VerifySriRobotCallback;
use App\Domain\Sri\Models\SriRobotCallback as SriRobotCallbackEvent;
use App\Domain\Sri\Models\SriSyncRun;
use App\Http\Controllers\Controller;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Recibe el callback firmado del robot y responde `202`.
 *
 * El middleware ya verificó firma, ventana temporal y tamaño. Aquí se hace la
 * deduplicación por `event_id` y se correlaciona el evento con el run que lo
 * originó, sin depender del tenant: el `event_id` es un ULID global.
 */
final class SriRobotCallbackController extends Controller
{
    public function __invoke(Request $request): JsonResponse
    {
        $callback = $request->attributes->get(VerifySriRobotCallback::ATRIBUTO);

        if (! $callback instanceof SriRobotCallback) {
            return response()->json(['message' => 'Callback no verificado.'], 422);
        }

        try {
            DB::transaction(function () use ($callback): void {
                $run = SriSyncRun::query()
                    ->withoutGlobalScopes()
                    ->where('callback_event_id', $callback->eventId)
                    ->first();

                SriRobotCallbackEvent::query()->create([
                    'event_id' => $callback->eventId,
                    'run_id' => $run?->id,
                    'status' => $callback->status,
                    'request_id' => $callback->requestId,
                    'ruc' => $callback->ruc,
                    'periods' => $callback->periods,
                    'payload' => $callback->payload,
                    'signature_timestamp' => $callback->timestamp,
                    'received_at' => now(),
                ]);

                if ($run !== null) {
                    $this->avanzar($run, $callback);
                }
            });
        } catch (UniqueConstraintViolationException) {
            Log::info('callback del robot repetido', ['event_id' => $callback->eventId]);

            return response()->json(['status' => 'accepted'], 202);
        }

        Log::info('callback del robot recibido', [
            'event_id' => $callback->eventId,
            'status' => $callback->status,
            'request_id' => $callback->requestId,
        ]);

        return response()->json(['status' => 'accepted'], 202);
    }

    /**
     * Refleja el resultado del robot en el run, sin reabrir estados finales.
     *
     * Un `failed` del robot cierra el run: la reconciliación ya no encontrará
     * archivos. `completed` y `partial` dejan el run en `downloading` para que
     * `sri:reconcile-runs` valide el manifiesto y lo pase a `parsing`.
     */
    private function avanzar(SriSyncRun $run, SriRobotCallback $callback): void
    {
        if (! $run->isActive()) {
            return;
        }

        if ($callback->isFailure()) {
            $run->markFailed('robot_failed', 'El robot no pudo completar la descarga.');

            return;
        }

        if ($run->status === SriSyncRunStatus::Invoking) {
            $run->transitionTo(SriSyncRunStatus::Downloading);
        }
    }
}
