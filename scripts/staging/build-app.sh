#!/usr/bin/env bash
# =============================================================================
# Prepara la aplicación DENTRO del contenedor `app` de staging (ADR-0008).
#
# La instancia no compila PHP ni instala Node ni Composer: la imagen base de
# GHCR ya trae PHP + extensiones + Composer + Node 24 + git + rsync. Lo único
# que se ejecuta en caliente es lo que cambia con el código:
#
#   - `composer install`  (solo si cambió composer.lock o falta vendor/)
#   - `npm ci` + `npm run build` (solo si cambió package-lock.json o falta
#     public/build, o si se pide --assets)
#   - `php artisan optimize`
#
# Los resultados se guardan en ./storage/.deploy y `deploy.sh` los reutiliza
# mientras el código no cambie, así que actualizar una instancia existente solo
# paga Vite cuando de verdad tocaste el frontend.
#
# Uso (desde el host, vía docker compose):
#   docker compose -f docker-compose.staging.yml exec -T app \
#     ./scripts/staging/build-app.sh
#
# Variables:
#   FORCE_ASSETS=1   recompila assets aunque no haya cambiado package-lock.json
#   FORCE_COMPOSER=1 reinstala dependencias PHP aunque composer.lock no cambie
# =============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/var/www/html}"
STATE_DIR="${STATE_DIR:-$APP_DIR/storage/.deploy}"
STAMP_DIR="$STATE_DIR/stamps"

log() {
    printf '[build-app] %s\n' "$*"
}

cd "$APP_DIR"

# Límites pensados para una t3.micro con 1 GB de RAM: aquí corre el `npm ci` y
# el `vite build`, no un servidor. Con swap de 2 GB es suficiente.
NODE_OPTIONS="${NODE_OPTIONS:---max-old-space-size=768}"
export NODE_OPTIONS
export COMPOSER_ALLOW_SUPERUSER=1

# `npm ci` borra node_modules (tarda minutos) y aquí el disco es el volumen del
# host, no un tmpfs: mejor no tener nunca un node_modules a medias.
if [[ ! -w "$APP_DIR" || ! -w "$APP_DIR/public" ]]; then
    log "ERROR: el usuario del contenedor no puede escribir en $APP_DIR"
    exit 1
fi

mkdir -p "$STAMP_DIR" "$APP_DIR/public/build"

# Una instancia nueva arranca con `storage/` vacío (el volume nace sin las
# carpetas de escritura) y `artisan optimize` falla con «View path not found».
# `git clean` no borra estas rutas: git las ignora.
mkdir -p bootstrap/cache \
    storage/app/private \
    storage/app/public \
    storage/framework/cache/data \
    storage/framework/sessions \
    storage/framework/views \
    storage/framework/testing \
    storage/logs

hash_of() {
    local file="$1"
    if [[ -f "$file" ]]; then
        sha256sum "$file" | cut -d' ' -f1
    else
        printf 'missing'
    fi
}

stamp_matches() {
    local stamp="$1" want="$2"
    [[ -f "$stamp" ]] && [[ "$(cat "$stamp")" == "$want" ]]
}

# ¿Hay archivos más nuevos que el sello del deploy anterior? Es la señal de que
# `git reset` trajo cambios en esa carpeta. Se descarta el ruido de los
# directorios y de lo que vive fuera del árbol versionado.
newer_than_stamp() {
    local stamp="$1"
    shift
    [[ -f "$stamp" ]] || return 0

    find "$@" -type f -newer "$stamp" \
        ! -path './storage/*' \
        ! -path './node_modules/*' \
        ! -path './vendor/*' \
        ! -path './public/build/*' \
        ! -name '*.log' \
        -print -quit 2>/dev/null | grep -q .
}

composer_state() {
    hash_of composer.lock
}

assets_state() {
    hash_of package-lock.json
}

# ---------------------------------------------------------------- Composer ---
composer_want="$(composer_state)"
composer_stamp="$STAMP_DIR/composer.lock.sha256"

if [[ "${FORCE_COMPOSER:-0}" == "1" ]] || ! stamp_matches "$composer_stamp" "$composer_want" || [[ ! -f vendor/autoload.php ]]; then
    log "composer install (lock $(printf '%.12s' "$composer_want"))"
    composer install --no-dev --no-scripts --no-autoloader --prefer-dist \
        --no-interaction --no-progress
    composer dump-autoload --optimize --no-dev --no-scripts --no-interaction
    rm -f "$STAMP_DIR/assets.lock.sha256"
    printf '%s' "$composer_want" > "$composer_stamp"
else
    log "composer: sin cambios en composer.lock; se reutiliza vendor/"
fi

# ------------------------------------------------------------------- Assets ---
assets_want="$(assets_state)"
assets_stamp="$STAMP_DIR/assets.lock.sha256"
needs_assets=0
if [[ "${FORCE_ASSETS:-0}" == "1" ]]; then
    log "assets: FORCE_ASSETS=1"
    needs_assets=1
elif [[ ! -f public/build/manifest.json ]]; then
    log "assets: falta public/build/manifest.json"
    needs_assets=1
elif ! stamp_matches "$assets_stamp" "$assets_want"; then
    log "assets: package-lock.json cambió"
    needs_assets=1
elif [[ ! -f "$assets_stamp" ]] || newer_than_stamp "$assets_stamp" resources public package.json vite.config.ts; then
    log "assets: recursos más nuevos que el último deploy"
    needs_assets=1
fi

if [[ "$needs_assets" == "1" ]]; then
    log "npm ci"
    # `npm ci` borra node_modules; si quedó de un build anterior (por ejemplo,
    # creado como root y ahora escribe otro usuario) el borrado falla. Como aquí
    # no hay nada que preservar, se elimina antes.
    rm -rf node_modules
    npm ci --no-audit --no-fund
    log "npm run build"
    npm run build
    # El build se ejecuta como root y deja el `manifest.json` solo para root:
    # `app` corre con UID 1000 y el bind mount no cambia de dueño. Sin esto
    # Laravel no puede leer el manifiesto desde PHP-FPM.
    chmod -R a+rX public/build

    # npm crea (o vacía) `node_modules` en cada `ci`: si queda para root, el
    # siguiente build en caliente falla al escribir en carpetas ajenas.
    if [[ -d node_modules ]]; then
        chmod -R a+rwX node_modules || true
    fi
    printf '%s' "$assets_want" > "$assets_stamp"
else
    log "assets: sin cambios; se reutiliza public/build"
fi

log "artisan optimize"
# `package:discover` regenera bootstrap/cache/packages.php con lo que hay en
# vendor/. Sin esto, pasarse de `--dev` a `--no-dev` deja proveedores fantasma
# (Pail, Telescope…) en bootstrap/cache/services.php y `optimize` falla en el
# contenedor.
rm -f bootstrap/cache/packages.php bootstrap/cache/services.php
php artisan package:discover --ansi
php artisan optimize

if [[ "$(id -u)" == "0" ]]; then
    log "normalizando permisos de lo que escribió el build"
    chmod -R a+rX public/build vendor
    chown -R "${APP_UID:-1000}:${APP_GID:-1000}" storage 2>/dev/null || true
fi

log "aplicación lista para el nuevo código"
