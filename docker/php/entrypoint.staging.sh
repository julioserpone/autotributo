#!/bin/sh
# =============================================================================
# Entrypoint de staging (ADR-0008).
#
# Se ejecuta en app, queue, scheduler y reverb. Antes de lanzar el proceso:
#   1. Espera a que Neon acepte conexiones (arranque en frío / red).
#   2. Crea el enlace público de storage (idempotente).
#   3. Cachea configuración, rutas y vistas con el entorno REAL del contenedor.
#
# La configuración NO se cachea en el build: las variables se inyectan al
# arrancar el contenedor. Cambiar `.env` exige reiniciar los servicios.
# =============================================================================
set -eu

readonly MAX_DB_ATTEMPTS=30
readonly SLEEP_SECONDS=2

log() {
    printf '[entrypoint] %s\n' "$*"
}

wait_for_database() {
    attempt=0

    until php -r '
        require "vendor/autoload.php";
        $app = require "bootstrap/app.php";
        $app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        Illuminate\Support\Facades\DB::connection()->getPdo();
    ' >/dev/null 2>&1; do
        attempt=$((attempt + 1))

        if [ "$attempt" -ge "$MAX_DB_ATTEMPTS" ]; then
            log "la base de datos no responde tras ${MAX_DB_ATTEMPTS} intentos"
            exit 1
        fi

        log "esperando base de datos (intento ${attempt}/${MAX_DB_ATTEMPTS})"
        sleep "$SLEEP_SECONDS"
    done
}

wait_for_database

# Si el enlace ya existe, Laravel avisa y devuelve 0; se ignora cualquier fallo
# porque el enlace real que usa Caddy se crea en el host (ver deploy.sh).
php artisan storage:link >/dev/null 2>&1 || true

log "cacheando configuración, rutas y vistas"
php artisan optimize

log "iniciando: $*"
exec "$@"
