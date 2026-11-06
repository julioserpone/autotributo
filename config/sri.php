<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Consulta de autorizaciones (paquete sri-comprobantes-php)
    |--------------------------------------------------------------------------
    |
    | `package` usa los clientes SOAP del paquete para consultar el XML
    | autorizado por clave de acceso. `fake` devuelve resultados deterministas
    | para desarrollo y pruebas; nunca hace red.
    |
    */

    'authorization' => [
        'driver' => env('SRI_AUTHORIZATION_DRIVER', 'package'),
        'environment' => env('SRI_ENVIRONMENT', '1'),
        'connection_timeout' => (float) env('SRI_CONNECTION_TIMEOUT', 10),
        'timeout' => (float) env('SRI_TIMEOUT', 30),
        'retry' => [
            'attempts' => (int) env('SRI_RETRY_ATTEMPTS', 3),
            'base_delay_ms' => (int) env('SRI_RETRY_BASE_DELAY_MS', 250),
            'max_delay_ms' => (int) env('SRI_RETRY_MAX_DELAY_MS', 5000),
        ],
        // Los XML se leen primero del bucket del robot. La consulta al SRI
        // queda como respaldo; si la respuesta del SRI no es fiable en un
        // entorno, se puede desactivar con false.
        'fallback_to_sri' => (bool) env('SRI_AUTHORIZATION_FALLBACK_TO_SRI', true),
        // Ventana (días desde la emisión) en la que el endpoint de
        // autorizaciones devuelve el XML de forma fiable. Dentro de ella se
        // consulta primero al SRI; fuera, o si falla, se lee del S3 del robot.
        'window_days' => (int) env('SRI_AUTHORIZATION_WINDOW_DAYS', 45),
    ],

    /*
    |--------------------------------------------------------------------------
    | Robot de descarga del portal (AWS Lambda sri-txt-robot)
    |--------------------------------------------------------------------------
    |
    | Contrato real del handler:
    | {ruc, periodos[], tipos[], forzar, sealed_credentials?}. En modo
    | dinámico la app sella las credenciales con `public_key`; la clave
    | privada y el bucket de salida viven en la función Lambda.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | Límites de sincronización
    |--------------------------------------------------------------------------
    |
    | `max_days_per_run` corta un rango en varias invocaciones del robot.
    | `max_calls_per_minute` protege la cuenta del contribuyente en el portal
    | del SRI: se aplica por RUC y antes de invocar la Lambda.
    |
    */

    'sync' => [
        'max_days_per_run' => (int) env('SRI_SYNC_MAX_DAYS_PER_RUN', 93),
        'max_calls_per_minute' => (int) env('SRI_SYNC_MAX_CALLS_PER_MINUTE', 6),
        'import_queue' => env('SRI_SYNC_IMPORT_QUEUE', 'sri'),
        'import_tries' => (int) env('SRI_SYNC_IMPORT_TRIES', 3),
        'import_timeout' => (int) env('SRI_SYNC_IMPORT_TIMEOUT', 90),
        'chunk_size' => (int) env('SRI_SYNC_CHUNK_SIZE', 25),
    ],

    'robot' => [
        'driver' => env('SRI_ROBOT_DRIVER', 'fake'),
        'function' => env('SRI_ROBOT_FUNCTION', 'sri-txt-robot'),
        'region' => env('SRI_ROBOT_REGION', env('AWS_DEFAULT_REGION', 'us-east-2')),
        'environment' => env('SRI_ROBOT_ENVIRONMENT', 'dev'),
        'output_disk' => env('SRI_ROBOT_OUTPUT_DISK', 'sri'),
        'output_bucket' => env('SRI_ROBOT_OUTPUT_BUCKET'),
        'output_prefix' => env('SRI_ROBOT_OUTPUT_PREFIX', ''),
        'deadline_seconds' => (int) env('SRI_ROBOT_DEADLINE_SECONDS', 840),
        'callback_url' => env('SRI_ROBOT_CALLBACK_URL'),
        'callback_secret' => env('SRI_ROBOT_CALLBACK_SECRET'),
        // Ventana de aceptación de la firma y tamaño máximo del cuerpo.
        'callback_tolerance_seconds' => (int) env('SRI_ROBOT_CALLBACK_TOLERANCE_SECONDS', 300),
        'callback_max_bytes' => (int) env('SRI_ROBOT_CALLBACK_MAX_BYTES', 262_144),
        'public_key' => env('SRI_ROBOT_PUBLIC_KEY'),
    ],

];
