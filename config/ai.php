<?php

declare(strict_types=1);

return [

    /*
    |--------------------------------------------------------------------------
    | Clasificación de ítems con IA
    |--------------------------------------------------------------------------
    |
    | El clasificador se usa solo como respaldo del motor de reglas. `driver`
    | selecciona el proveedor (`fake` no hace red) y el resto de valores
    | gobierna la llamada: modelo, tiempos máximos, reintentos y umbral de
    | confianza para aceptar una sugerencia sin revisión humana.
    |
    | El presupuesto, la caché por hash y el registro de `llm_calls` se
    | implementan en F3-04. Aquí solo se prepara el contrato y la resiliencia.
    |
    */

    'classifier' => [
        'driver' => env('LLM_DRIVER', 'fake'),
        'model' => env('LLM_MODEL', 'gpt-4.1-mini'),
        'max_output_tokens' => max(1, (int) env('LLM_MAX_OUTPUT_TOKENS', 512)),
        'timeout' => (float) env('LLM_TIMEOUT', 20),
        'connect_timeout' => (float) env('LLM_CONNECT_TIMEOUT', 5),
        // Intentos totales, no reintentos: 2 significa la llamada original
        // más un reintento cuando el proveedor devuelve una salida inválida.
        'max_attempts' => max(1, (int) env('LLM_MAX_ATTEMPTS', 2)),
        'confidence_threshold' => (float) env('LLM_CONFIDENCE_THRESHOLD', 0.80),
        'batch_size' => max(1, (int) env('AI_CLASSIFY_BATCH_SIZE', 40)),

        'circuit' => [
            'failure_threshold' => max(1, (int) env('LLM_CIRCUIT_FAILURE_THRESHOLD', 5)),
            'cooldown_seconds' => max(1, (int) env('LLM_CIRCUIT_COOLDOWN_SECONDS', 60)),
        ],
    ],

    'openai' => [
        'base_url' => env('OPENAI_BASE_URL', 'https://api.openai.com/v1'),
        'api_key' => env('OPENAI_API_KEY'),
    ],

    'anthropic' => [
        'base_url' => env('ANTHROPIC_BASE_URL', 'https://api.anthropic.com/v1'),
        'api_key' => env('ANTHROPIC_API_KEY'),
        'model' => env('ANTHROPIC_MODEL', 'claude-sonnet-4-5'),
        'version' => env('ANTHROPIC_VERSION', '2023-06-01'),
    ],

];
