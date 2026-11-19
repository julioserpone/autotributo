#!/usr/bin/env bash
# =============================================================================
# Regenera la imagen de GHCR desde una copia del repositorio (ADR-0008).
#
# Este script NO se ejecuta en la EC2. Se ejecuta en tu máquina (o en un runner)
# para:
#
#   1. Exportar un commit a un directorio limpio (sin .git, node_modules, .env…).
#   2. Abrir un clon temporal de `APP_IMAGE` con el toolchain ya instalado.
#   3. Proyectar el código nuevo dentro de la imagen con rsync.
#   4. Ejecutar en ese clon solo lo que cambia con el código:
#        composer install (si cambió composer.lock)
#        npm ci && npm run build (si cambió package-lock.json o resources/)
#        php artisan optimize
#   5. Guardar la diferencia (`docker commit`) como nueva `IMAGE_TAG_REF`.
#
# Resultado: cambiar una línea de PHP y actualizar la imagen cuesta segundos o
# un par de minutos (Vite), nunca los ~5-10 min de compilar extensiones o
# instalar Node/Composer. La instancia hace `docker pull` normal con esa imagen.
#
# Uso:
#   REF=main IMAGE_TAG_REF=ghcr.io/julioserpone/autotributo-staging:main \
#     ./scripts/staging/image-refresh.sh
#   REF=<sha> FORCE_ASSETS=1 ./scripts/staging/image-refresh.sh
#
# Opcionales:
#   REPO_URL          default: origin del repositorio actual
#   PUSH=1            hace `docker push` del tag resultante
#   FORCE_COMPOSER=1  reinstala dependencias PHP aunque composer.lock no cambie
#   FORCE_ASSETS=1    recompila assets aunque package-lock.json no cambie
#   PLATFORM          default: linux/amd64 (la arquitectura de la EC2)
# =============================================================================
set -euo pipefail

REPO_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"
REF="${REF:-main}"
IMAGE_SRC="${IMAGE_SRC:-ghcr.io/julioserpone/autotributo-staging:staging}"
IMAGE_TAG_REF="${IMAGE_TAG_REF:-ghcr.io/julioserpone/autotributo-staging:main}"
PLATFORM="${PLATFORM:-linux/amd64}"
PUSH="${PUSH:-0}"
FORCE_COMPOSER="${FORCE_COMPOSER:-0}"
FORCE_ASSETS="${FORCE_ASSETS:-0}"

log() {
    printf '[image-refresh] %s\n' "$*"
}

fail() {
    printf '[image-refresh] ERROR: %s\n' "$*" >&2
    exit 1
}

command -v docker >/dev/null 2>&1 || fail "docker no está instalado"
git -C "$REPO_DIR" rev-parse --git-dir >/dev/null 2>&1 || fail "$REPO_DIR no es un repositorio git"

workdir="$(mktemp -d "${TMPDIR:-/tmp}/autotributo-image.XXXXXX")"
container="autotributo-image-refresh-$$"

cleanup() {
    docker rm -f "$container" >/dev/null 2>&1 || true
    rm -rf "$workdir"
}
trap cleanup EXIT

log "exportando ${REF} a $workdir/src"
mkdir -p "$workdir/src"
git -C "$REPO_DIR" archive --format=tar "$REF" | tar -xf - -C "$workdir/src"
[[ -f "$workdir/src/composer.lock" ]] || fail "el commit ${REF} no tiene composer.lock"

selected_platform=()
if [[ -n "$PLATFORM" ]]; then
    selected_platform=(--platform "$PLATFORM")
fi

log "descargando toolchain ${IMAGE_SRC}"
docker pull "${selected_platform[@]}" "$IMAGE_SRC" >/dev/null

log "abriendo un contenedor de trabajo sobre ${IMAGE_SRC}"
docker run -d --name "$container" "${selected_platform[@]}" \
    --entrypoint /bin/sh "$IMAGE_SRC" -c 'sleep infinity' >/dev/null

log "sincronizando el código en /var/www/html"
# `--delete` deja el árbol igual al commit exportado (no hereda archivos viejos)
# y protege el almacenamiento del contenedor.
docker exec "$container" mkdir -p /var/www/html
docker exec "$container" mkdir -p /tmp/src
docker cp "$workdir/src/." "$container:/tmp/src/"
docker exec "$container" rsync -a --delete \
    --exclude 'storage/' \
    --exclude '.env' \
    /tmp/src/ /var/www/html/
docker exec "$container" rm -rf /tmp/src

log "composer, assets y optimize (solo lo que cambió)"
docker exec \
    -e FORCE_COMPOSER="$FORCE_COMPOSER" \
    -e FORCE_ASSETS="$FORCE_ASSETS" \
    "$container" /var/www/html/scripts/staging/build-app.sh

log "guardando la nueva imagen como ${IMAGE_TAG_REF}"
docker commit \
    --change 'USER app' \
    --change 'EXPOSE 9000' \
    --change 'ENTRYPOINT ["/usr/local/bin/entrypoint.staging"]' \
    --change 'CMD ["php-fpm"]' \
    "$container" "$IMAGE_TAG_REF" >/dev/null

if [[ "$PUSH" == "1" ]]; then
    log "publicando ${IMAGE_TAG_REF}"
    docker push "$IMAGE_TAG_REF"
fi

log "listo: ${IMAGE_TAG_REF}"
log "en la instancia: APP_IMAGE=${IMAGE_TAG_REF} ./scripts/staging/deploy.sh"
