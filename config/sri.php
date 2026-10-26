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
