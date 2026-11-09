<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Artisan;

/**
 * El contrato OpenAPI se versiona en el repo y el frontend genera tipos desde
 * él. Si una ruta cambia y el archivo no se regenera, el cliente queda
 * desincronizado sin que nadie lo note: este test lo detecta en CI.
 */
it('mantiene docs/openapi.json sincronizado con las rutas', function (): void {
    $path = base_path('docs/openapi.json');
    $committed = file_get_contents($path);

    expect($committed)->not->toBeFalse();

    // El servidor exportado depende de APP_URL; se fija para que el test sea
    // determinista en cualquier entorno (local y CI).
    config(['app.url' => 'http://localhost']);

    Artisan::call('scramble:export');

    $regenerated = file_get_contents($path);

    // Se restaura el archivo versionado para no ensuciar el árbol de trabajo.
    file_put_contents($path, (string) $committed);

    expect($regenerated)->toBe($committed);
});

it('documenta los endpoints de la etapa', function (): void {
    $spec = json_decode((string) file_get_contents(base_path('docs/openapi.json')), true);

    expect($spec)->toBeArray()
        ->and($spec['paths'])->toHaveKeys([
            '/v1/companies',
            '/v1/companies/{company}/credential',
            '/v1/sync-runs',
            '/v1/sync-runs/{run}',
            '/v1/audit-logs',
            '/v1/realtime/status',
        ]);
});
