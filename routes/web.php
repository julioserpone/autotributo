<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Route;

// La SPA usa Vue Router en modo history. Las rutas de API y /up se resuelven
// antes que este fallback; el resto devuelve el shell de la aplicación.
Route::get('/{path?}', fn () => view('app'))
    ->where('path', '^(?!api|up|build|storage).*$')
    ->name('spa');
