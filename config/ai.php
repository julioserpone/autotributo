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
    | El presupuesto, la caché por hash y el registro de `llm_calls` viven en
    | `budget`, `cache` y `pricing`. Un `budget.monthly_usd` de 0 desactiva el
    | límite (valor de los entornos de prueba).
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

    /*
    |--------------------------------------------------------------------------
    | Caché por huella de entrada
    |--------------------------------------------------------------------------
    |
    | Reutiliza la última clasificación exitosa con la misma descripción
    | normalizada, rubro, catálogo, modelo y versión de prompt. No cuesta
    | tokens y no guarda la descripción del ítem.
    |
    */

    'cache' => [
        'enabled' => (bool) env('LLM_CACHE_ENABLED', true),
        'ttl_days' => max(1, (int) env('LLM_CACHE_TTL_DAYS', 30)),
    ],

    /*
    |--------------------------------------------------------------------------
    | Presupuesto de IA
    |--------------------------------------------------------------------------
    |
    | Límite mensual por tenant en USD. Al agotarse no se llama al proveedor y
    | el ítem se degrada a revisión manual. `warn_ratio` emite un aviso en el
    | log (una vez al mes por tenant) antes del corte.
    |
    */

    'budget' => [
        'monthly_usd' => (float) env('LLM_MONTHLY_BUDGET_USD', 50),
        'warn_ratio' => min(1.0, max(0.0, (float) env('LLM_BUDGET_WARN_RATIO', 0.8))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Precios de referencia
    |--------------------------------------------------------------------------
    |
    | Micros de USD por millón de tokens (entero, sin float). Actualizar desde
    | la página de precios del proveedor cuando cambien. Un modelo sin entrada
    | se registra con coste 0; en capas gratuitas el coste real es 0, pero la
    | tabla usa el precio pagado para no subestimar el presupuesto al migrar.
    |
    */

    'pricing' => [
        'models' => [
            'gpt-4.1-mini' => ['input' => 400_000, 'output' => 1_600_000],
            'gpt-4.1' => ['input' => 2_000_000, 'output' => 8_000_000],
            'claude-sonnet-4-5' => ['input' => 3_000_000, 'output' => 15_000_000],
            'llama-3.3-70b-versatile' => ['input' => 590_000, 'output' => 790_000],
            'llama-3.1-8b-instant' => ['input' => 50_000, 'output' => 80_000],
            'gemini-2.5-flash-lite' => ['input' => 100_000, 'output' => 400_000],
            'gemini-2.5-flash' => ['input' => 300_000, 'output' => 2_500_000],
        ],
        'default' => ['input' => 0, 'output' => 0],
    ],

];
