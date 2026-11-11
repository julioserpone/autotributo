#!/usr/bin/env bash
# =============================================================================
# Actualiza el registro A de DuckDNS con la IP pública actual de la instancia.
#
# ADR-0008: staging usa IP dinámica, sin Elastic IP ni Route 53. Este script lo
# ejecuta el timer `autotributo-duckdns.timer` al arrancar y cada 5 minutos.
#
# Configuración (root, 600): /etc/autotributo/duckdns.env
#   DUCKDNS_DOMAIN=autotributo-staging      # sin .duckdns.org
#   DUCKDNS_TOKEN=xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx
#
# El token viaja en la URL de DuckDNS (su API solo admite GET). No se registra
# en logs: solo se escribe el resultado OK/KO.
# =============================================================================
set -euo pipefail

ENV_FILE="${DUCKDNS_ENV_FILE:-/etc/autotributo/duckdns.env}"

log() {
    if command -v logger >/dev/null 2>&1; then
        logger -t autotributo-duckdns -- "$*"
    else
        printf '[duckdns] %s\n' "$*"
    fi
}

if [[ ! -r "$ENV_FILE" ]]; then
    log "no se puede leer ${ENV_FILE}"
    exit 1
fi

set -a
# shellcheck source=/dev/null
source "$ENV_FILE"
set +a

: "${DUCKDNS_DOMAIN:?DUCKDNS_DOMAIN no definido en ${ENV_FILE}}"
: "${DUCKDNS_TOKEN:?DUCKDNS_TOKEN no definido en ${ENV_FILE}}"

response="$(curl --silent --show-error --fail --max-time 15 \
    "https://www.duckdns.org/update?domains=${DUCKDNS_DOMAIN}&token=${DUCKDNS_TOKEN}&ip=")"

if [[ "$response" != "OK" ]]; then
    log "DuckDNS respondió: ${response:-<vacío>}"
    exit 1
fi

log "registro A actualizado: ${DUCKDNS_DOMAIN}.duckdns.org"
