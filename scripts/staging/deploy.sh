#!/usr/bin/env bash
# =============================================================================
# Deploy de staging en la EC2 (ADR-0008).
#
# Lo ejecuta el usuario de despliegue en la propia instancia, o GitHub Actions
# por SSH (`.github/workflows/deploy-staging.yml`). Es idempotente y usa un lock
# para impedir dos deploys simultáneos.
#
# Requisitos en el host:
#   - Repositorio en /opt/autotributo (o APP_DIR) con acceso a origin.
#   - `.env` de staging (copiado de `.env.staging.example` y completado).
#   - Docker + Compose plugin, curl, git.
#
# La instancia NO compila PHP ni instala Node/Composer: la imagen base de GHCR
# ya trae el toolchain. Lo que cambia con el código (Composer y Vite) se ejecuta
# en caliente dentro del contenedor y solo si el lock o los recursos cambian
# (`scripts/staging/build-app.sh`).
#
# Pasos:
#   1. Actualiza el código del host a origin/<BRANCH> (shallow).
#   2. Con APP_IMAGE, descarga la imagen de GHCR; sin ella, construye el
#      contenedor `app` (segundos: solo copia el código).
#   3. Composer/npm en caliente (solo si cambió el lock o los assets).
#   4. Migraciones, recrea servicios y verifica /up.
# =============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/autotributo}"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.staging.yml}"
BRANCH="${BRANCH:-main}"
LOCK_FILE="${LOCK_FILE:-/tmp/autotributo-staging-deploy.lock}"
HEALTH_ATTEMPTS="${HEALTH_ATTEMPTS:-30}"
HEALTH_SLEEP="${HEALTH_SLEEP:-5}"

# Lee APP_IMAGE de .env si no viene por entorno (sin comillas).
APP_IMAGE="${APP_IMAGE:-}"
if [[ -z "$APP_IMAGE" && -f .env ]]; then
    APP_IMAGE="$(sed -n 's/^APP_IMAGE=//p' .env | tail -n1)"
    APP_IMAGE="${APP_IMAGE#\"}"; APP_IMAGE="${APP_IMAGE%\"}"
    APP_IMAGE="${APP_IMAGE#\'}"; APP_IMAGE="${APP_IMAGE%\'}"
fi

log() {
    printf '[deploy] %s\n' "$*"
}

fail() {
    printf '[deploy] ERROR: %s\n' "$*" >&2
    exit 1
}

# Actualiza el árbol del host al último commit de la rama. Traer solo ese commit
# evita clonar el historial completo y deja el repo en un estado reproducible.
sync_code() {
    if [[ "${SKIP_GIT_PULL:-0}" == "1" ]]; then
        log "SKIP_GIT_PULL=1: se conserva el código actual (rollback manual)"
        return
    fi

    [[ -d .git ]] || {
        log "sin repositorio git en ${APP_DIR}; se usa el código ya presente"
        return
    }

    log "actualizando código desde origin/${BRANCH}"
    git fetch --quiet --prune --depth 1 origin "$BRANCH"
    git reset --quiet --hard "origin/${BRANCH}"

    # Conserva secretos, storage, dependencias y assets ya construidos.
    git clean -qfd \
        -e .env \
        -e storage \
        -e public/build \
        -e public/storage \
        -e node_modules \
        -e vendor
}

cd "$APP_DIR" || fail "no existe APP_DIR=${APP_DIR}"

[[ -f "$COMPOSE_FILE" ]] || fail "no existe ${COMPOSE_FILE} en ${APP_DIR}"
[[ -f .env ]] || fail "falta .env en ${APP_DIR} (copiar .env.staging.example)"

# Un solo deploy a la vez (el descriptor 9 se libera al salir).
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    fail "hay otro deploy en curso (${LOCK_FILE})"
fi

STAGING_HOSTNAME="$(sed -n 's/^STAGING_HOSTNAME=//p' .env | tail -n1)"
STAGING_HOSTNAME="${STAGING_HOSTNAME#\"}"; STAGING_HOSTNAME="${STAGING_HOSTNAME%\"}"
STAGING_HOSTNAME="${STAGING_HOSTNAME#\'}"; STAGING_HOSTNAME="${STAGING_HOSTNAME%\'}"
[[ -n "$STAGING_HOSTNAME" ]] || fail "STAGING_HOSTNAME está vacío en .env"

sync_code

if [[ -n "$APP_IMAGE" ]]; then
    log "descargando imagen ${APP_IMAGE}"
    docker pull "$APP_IMAGE"
    docker tag "$APP_IMAGE" autotributo/php:staging

    # Si el código del host no está (sin .git o sin red), se siembra desde la
    # imagen: es el respaldo mínimo para arrancar.
    if [[ ! -f vendor/autoload.php ]]; then
        log "extrayendo código desde la imagen (respaldo)"
        tmp_container="autotributo-extract-$$"
        docker create --name "$tmp_container" --entrypoint /bin/sh "$APP_IMAGE" -c true >/dev/null
        for path in app bootstrap config database public resources routes vendor; do
            docker cp "$tmp_container:/var/www/html/${path}/." "./${path}/" \
                || fail "no se pudo extraer ${path}/ desde la imagen"
        done
        docker rm "$tmp_container" >/dev/null 2>&1 || true
    fi
else
    # Build del contenedor: la base de GHCR ya trae PHP + extensiones + Node +
    # Composer, así que aquí solo se copia el código (segundos).
    log "construyendo el contenedor 'app' sobre la base de GHCR"
    docker compose -f "$COMPOSE_FILE" --env-file .env build --pull app
fi

# APP_VERSION se escribe con el commit desplegado para que /api/v1/health diga
# qué código corre. El entrypoint re-cachea la configuración al arrancar.
CURRENT_VERSION="$(git rev-parse HEAD 2>/dev/null || true)"
if [[ -n "$CURRENT_VERSION" ]]; then
    sed -i "/^APP_VERSION=/d" .env
    printf 'APP_VERSION=%s\n' "$CURRENT_VERSION" >> .env
fi

# Composer y Vite en caliente, dentro del contenedor, solo si cambió el lock o
# los recursos. En una instancia nueva la imagen ya los trae y este paso es una
# lectura.
log "preparando dependencias y assets (composer/npm en caliente)"
docker compose -f "$COMPOSE_FILE" --env-file .env run --rm --no-deps -u 0:0 app \
    ./scripts/staging/build-app.sh

log "aplicando migraciones"
docker compose -f "$COMPOSE_FILE" --env-file .env run --rm --no-deps app \
    php artisan migrate --force

log "recreando servicios"
docker compose -f "$COMPOSE_FILE" --env-file .env up -d --remove-orphans \
    --wait --wait-timeout 180

# Caddy sirve /storage desde el host; el enlace se crea fuera del contenedor.
ln -sfn ../storage/app/public public/storage

log "reiniciando workers para que tomen el código nuevo"
docker compose -f "$COMPOSE_FILE" --env-file .env exec -T queue \
    php artisan queue:restart || true

log "verificando https://${STAGING_HOSTNAME}/up"
healthy=0
for ((attempt = 1; attempt <= HEALTH_ATTEMPTS; attempt++)); do
    if curl --fail --silent --show-error --max-time 10 \
        --resolve "${STAGING_HOSTNAME}:443:127.0.0.1" \
        "https://${STAGING_HOSTNAME}/up" >/dev/null 2>&1; then
        healthy=1
        break
    fi

    log "health check ${attempt}/${HEALTH_ATTEMPTS} falló; reintentando en ${HEALTH_SLEEP}s"
    sleep "$HEALTH_SLEEP"
done

if [[ "$healthy" -ne 1 ]]; then
    docker compose -f "$COMPOSE_FILE" --env-file .env ps >&2 || true
    docker compose -f "$COMPOSE_FILE" --env-file .env logs --tail=80 app web reverb >&2 || true
    fail "el health check no pasó"
fi

log "deploy completado: https://${STAGING_HOSTNAME}"
if [[ -n "$CURRENT_VERSION" ]]; then
    log "versión desplegada: ${CURRENT_VERSION}"
fi
