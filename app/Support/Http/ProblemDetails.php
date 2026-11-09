<?php

declare(strict_types=1);

namespace App\Support\Http;

use Illuminate\Http\JsonResponse;

/**
 * Respuesta de error en `application/problem+json` (RFC 9457).
 *
 * Contrato único para toda la API: `type`, `title`, `status`, `detail` y,
 * cuando aplica, `errors[]` con la ruta del campo. Los clientes pueden
 * distinguir errores por `type` sin parsear mensajes en español.
 */
final class ProblemDetails
{
    public const CONTENT_TYPE = 'application/problem+json';

    public const BASE_URI = 'https://autotributo.com/problems/';

    /**
     * @param  array<string, list<string>>  $errors
     * @param  array<string, mixed>  $extra
     */
    public static function make(
        int $status,
        string $type,
        string $title,
        ?string $detail = null,
        array $errors = [],
        array $extra = [],
    ): JsonResponse {
        $body = [
            'type' => self::BASE_URI.$type,
            'title' => $title,
            'status' => $status,
        ];

        if ($detail !== null && $detail !== '') {
            $body['detail'] = $detail;
        }

        if ($errors !== []) {
            $body['errors'] = $errors;
        }

        // `json()` fija `application/json`; se sobrescribe después para que el
        // header quede exactamente en `application/problem+json`.
        return response()
            ->json([...$body, ...$extra], $status)
            ->header('Content-Type', self::CONTENT_TYPE);
    }

    /**
     * @param  array<string, list<string>>  $errors
     */
    public static function validation(array $errors, string $detail = 'La solicitud contiene datos inválidos.'): JsonResponse
    {
        return self::make(422, 'validation-failed', 'Datos inválidos', $detail, $errors);
    }

    public static function unauthorized(string $detail = 'Debe iniciar sesión para continuar.'): JsonResponse
    {
        return self::make(401, 'unauthenticated', 'No autenticado', $detail);
    }

    public static function forbidden(string $detail = 'No tiene permiso para realizar esta acción.'): JsonResponse
    {
        return self::make(403, 'forbidden', 'Acceso denegado', $detail);
    }

    public static function notFound(string $detail = 'El recurso solicitado no existe.'): JsonResponse
    {
        return self::make(404, 'not-found', 'Recurso no encontrado', $detail);
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    public static function conflict(string $type, string $detail, array $extra = []): JsonResponse
    {
        return self::make(409, $type, 'Conflicto con el estado actual', $detail, [], $extra);
    }

    public static function tooManyRequests(int $retryAfter): JsonResponse
    {
        return self::make(
            429,
            'rate-limited',
            'Demasiadas solicitudes',
            "Reintente en {$retryAfter} segundos.",
            [],
            ['retry_after' => $retryAfter],
        )->header('Retry-After', (string) $retryAfter);
    }

    public static function serverError(string $detail = 'Ocurrió un error inesperado.'): JsonResponse
    {
        return self::make(500, 'server-error', 'Error interno', $detail);
    }
}
