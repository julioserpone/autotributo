<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Datos de demo para staging
    |--------------------------------------------------------------------------
    |
    | Solo se usan cuando se recrea la base de datos (`migrate:fresh --seed`).
    | `DEMO_SEED` activa la ejecución del `DemoTenantSeeder`; `DEMO_RUC` y
    | `DEMO_SRI_PASSWORD` son inyectados por `ec2-control.sh create` cuando se
    | pasan `--ruc` y `--password`. La contraseña de los usuarios de prueba
    | (`DEMO_USER_PASSWORD`) por defecto es `password`.
    |
    */
    'seed' => (bool) env('DEMO_SEED', false),
    'ruc' => env('DEMO_RUC'),
    'sri_password' => env('DEMO_SRI_PASSWORD'),
    'user_password' => env('DEMO_USER_PASSWORD', 'password'),
];
