<?php

declare(strict_types=1);

namespace App\Domain\Sri\Http\Middleware;

use App\Domain\Sri\Data\SriRobotCallback;
use Closure;
use Illuminate\Http\Request;
use JsonException;
use Symfony\Component\HttpFoundation\Response;

/**
 * Verifica el callback firmado del robot SRI antes de tocar la base.
 *
 * Contrato (callback v1):
 *
 * - Firma `sha256=HMAC-SHA256(secreto, "{X-Sri-Timestamp}.{cuerpo exacto}")`.
 * - Ventana temporal para limitar la reutilización de una firma capturada.
 * - Cuerpo pequeño: el callback solo transporta el resumen de la ejecución.
 *
 * Un fallo de configuración responde `503` para que el robot reintente; una
 * firma o un cuerpo inválidos no se reintentan (`4xx`).
 */
final class VerifySriRobotCallback
{
    public const ATRIBUTO = 'sri.callback';

    /** @var list<string> */
    private const ESTADOS = ['completed', 'partial', 'failed'];

    public function handle(Request $request, Closure $next): Response
    {
        $secret = trim((string) config('sri.robot.callback_secret', ''));

        if ($secret === '') {
            return response()->json(['message' => 'Callback del robot no configurado.'], 503);
        }

        $maximo = (int) config('sri.robot.callback_max_bytes', 262_144);
        $declarado = (int) $request->server('CONTENT_LENGTH', 0);

        if ($declarado > $maximo) {
            return response()->json(['message' => 'Callback demasiado grande.'], 413);
        }

        $cuerpo = $request->getContent();

        if (strlen($cuerpo) > $maximo) {
            return response()->json(['message' => 'Callback demasiado grande.'], 413);
        }

        $eventId = trim((string) $request->header('X-Sri-Event-Id', ''));
        $timestamp = trim((string) $request->header('X-Sri-Timestamp', ''));
        $firma = trim((string) $request->header('X-Sri-Signature', ''));

        if ($eventId === '' || strlen($eventId) > 128 || ! ctype_digit($timestamp) || $firma === '') {
            return response()->json(['message' => 'Cabeceras del callback incompletas.'], 422);
        }

        $momento = (int) $timestamp;
        $tolerancia = (int) config('sri.robot.callback_tolerance_seconds', 300);

        if (abs(time() - $momento) > $tolerancia) {
            return response()->json(['message' => 'Callback fuera de la ventana temporal.'], 401);
        }

        $esperada = 'sha256='.hash_hmac('sha256', $timestamp.'.'.$cuerpo, $secret);

        if (! hash_equals($esperada, $firma)) {
            return response()->json(['message' => 'Firma del callback inválida.'], 401);
        }

        $datos = $this->decodificar($cuerpo);

        if ($datos === null) {
            return response()->json(['message' => 'Cuerpo del callback inválido.'], 422);
        }

        if (($datos['event_id'] ?? null) !== $eventId) {
            return response()->json(['message' => 'El event_id del cuerpo no coincide.'], 422);
        }

        if (($datos['timestamp'] ?? null) !== $momento) {
            return response()->json(['message' => 'El timestamp del cuerpo no coincide.'], 422);
        }

        if (($datos['schema_version'] ?? null) !== 1 || ($datos['event_type'] ?? null) !== 'sri.robot.finished') {
            return response()->json(['message' => 'Versión del callback no soportada.'], 422);
        }

        $estado = $datos['status'] ?? null;

        if (! is_string($estado) || ! in_array($estado, self::ESTADOS, true)) {
            return response()->json(['message' => 'Estado del callback no válido.'], 422);
        }

        $request->attributes->set(self::ATRIBUTO, new SriRobotCallback(
            eventId: $eventId,
            timestamp: $momento,
            status: $estado,
            requestId: $this->texto($datos['request_id'] ?? null),
            ruc: $this->texto($datos['ruc'] ?? null),
            periods: $this->periodos($datos['periodos'] ?? null),
            error: $this->texto($datos['error'] ?? null),
            payload: $datos,
        ));

        return $next($request);
    }

    /**
     * @return array<string, mixed>|null
     */
    private function decodificar(string $cuerpo): ?array
    {
        try {
            $datos = json_decode($cuerpo, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        return is_array($datos) ? $datos : null;
    }

    private function texto(mixed $valor): ?string
    {
        if (! is_string($valor)) {
            return null;
        }

        $valor = trim($valor);

        return $valor === '' ? null : $valor;
    }

    /**
     * @return list<string>
     */
    private function periodos(mixed $valor): array
    {
        if (! is_array($valor)) {
            return [];
        }

        return array_values(array_filter(
            $valor,
            static fn (mixed $periodo): bool => is_string($periodo) && preg_match('/^\d{4}-\d{2}(-\d{2})?$/', $periodo) === 1,
        ));
    }
}
