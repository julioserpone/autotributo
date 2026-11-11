#!/usr/bin/env bash
# =============================================================================
# Deploy de staging en la EC2 (ADR-0008).
#
# Lo ejecuta el usuario de despliegue en la propia instancia, o GitHub Actions
# por SSH (`.github/workflows/deploy-staging.yml`). Es idempotente y usa un lock
# para impedir dos deploys simultáneos.
#
# Requisitos en el host:
#   - Repositorio en /opt/autotributo (o APP_DIR).
#   - `.env` de staging (copiado de `.env.staging.example` y completado).
#   - Docker + Compose plugin, Node 24 y make/curl/git.
#
# Pasos:
#   1. Actualiza el código a origin/main (reset duro: staging no tiene cambios).
#   2. Compila los assets en el host (`npm ci && npm run build`).
#   3. Construye la imagen y aplica migraciones.
#   4. Recrea los servicios y espera los healthchecks.
#   5. Verifica https://STAGING_HOSTNAME/up.
# =============================================================================
set -euo pipefail

APP_DIR="${APP_DIR:-/opt/autotributo}"
COMPOSE_FILE="${COMPOSE_FILE:-docker-compose.staging.yml}"
BRANCH="${BRANCH:-main}"
LOCK_FILE="${LOCK_FILE:-/tmp/autotributo-staging-deploy.lock}"
HEALTH_ATTEMPTS="${HEALTH_ATTEMPTS:-30}"
HEALTH_SLEEP="${HEALTH_SLEEP:-5}"
SKIP_GIT_PULL="${SKIP_GIT_PULL:-0}"

log() {
    printf '[deploy] %s\n' "$*"
}

fail() {
    printf '[deploy] ERROR: %s\n' "$*" >&2
    exit 1
}

cd "$APP_DIR" || fail "no existe APP_DIR=${APP_DIR}"

[[ -f "$COMPOSE_FILE" ]] || fail "no existe ${COMPOSE_FILE} en ${APP_DIR}"
[[ -f .env ]] || fail "falta .env en ${APP_DIR} (copiar .env.staging.example)"

# Un solo deploy a la vez (el descriptor 9 se libera al salir).
exec 9>"$LOCK_FILE"
if ! flock -n 9; then
    fail "hay otro deploy en curso (${LOCK_FILE})"
fi

STAGING_HOSTNAME="$(grep -E '^STAGING_HOSTNAME=' .env | tail -n1 | cut -d= -f2- | tr -d '"' | tr -d "'")"
[[ -n "$STAGING_HOSTNAME" ]] || fail "STAGING_HOSTNAME está vacío en .env"

if [[ "$SKIP_GIT_PULL" == "1" ]]; then
    log "SKIP_GIT_PULL=1: se usa el código ya presente (rollback manual)"
else
    log "actualizando código desde origin/${BRANCH}"
    git fetch --prune origin "$BRANCH"
    git reset --hard "origin/${BRANCH}"
fi

# Conserva secretos, storage, assets compilados y dependencias locales.
git clean -fd \
    -e .env \
    -e storage \
    -e public/build \
    -e public/storage \
    -e node_modules \
    -e vendor

log "compilando assets"
npm ci --no-audit --no-fund
npm run build

log "construyendo imagen de la aplicación"
docker compose -f "$COMPOSE_FILE" --env-file .env build app

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
