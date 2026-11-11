#!/usr/bin/env bash
# =============================================================================
# Prepara una EC2 Ubuntu 24.04 recién creada para staging (ADR-0008).
#
# Ejecutar como root (sudo). Es idempotente: se puede repetir tras clonar el
# repositorio para instalar las unidades de systemd.
#
#   sudo ./scripts/staging/provision-host.sh
#
# Instala Docker, Node 24, swap, UFW y el timer de DuckDNS. No clona el repo ni
# escribe secretos: eso lo hace el runbook (docs/13).
# =============================================================================
set -euo pipefail

if [[ "${EUID}" -ne 0 ]]; then
    echo "Ejecutar con sudo." >&2
    exit 1
fi

REPO_DIR="${REPO_DIR:-/opt/autotributo}"
DEPLOY_USER="${DEPLOY_USER:-ubuntu}"
SWAP_SIZE="${SWAP_SIZE:-2G}"

export DEBIAN_FRONTEND=noninteractive

log() {
    printf '[provision] %s\n' "$*"
}

log "instalando paquetes base"
apt-get update
apt-get install -y --no-install-recommends \
    ca-certificates \
    curl \
    git \
    gnupg \
    unzip \
    ufw

log "instalando Docker y Compose plugin"
if ! command -v docker >/dev/null 2>&1; then
    install -m 0755 -d /etc/apt/keyrings
    curl -fsSL https://download.docker.com/linux/ubuntu/gpg \
        -o /etc/apt/keyrings/docker.asc
    chmod a+r /etc/apt/keyrings/docker.asc

    # shellcheck disable=SC1091
    . /etc/os-release
    printf 'deb [arch=%s signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu %s stable\n' \
        "$(dpkg --print-architecture)" "$VERSION_CODENAME" \
        > /etc/apt/sources.list.d/docker.list

    apt-get update
    apt-get install -y \
        docker-ce \
        docker-ce-cli \
        containerd.io \
        docker-buildx-plugin \
        docker-compose-plugin
fi

if id "$DEPLOY_USER" >/dev/null 2>&1; then
    usermod -aG docker "$DEPLOY_USER"
else
    log "el usuario ${DEPLOY_USER} no existe; ajusta DEPLOY_USER"
fi

log "instalando Node 24"
if ! command -v node >/dev/null 2>&1 || [[ "$(node -v)" != v24* ]]; then
    curl -fsSL https://deb.nodesource.com/setup_24.x | bash -
    apt-get install -y nodejs
fi

log "configurando swap (${SWAP_SIZE})"
if ! swapon --show | grep -q '/swapfile'; then
    fallocate -l "$SWAP_SIZE" /swapfile
    chmod 600 /swapfile
    mkswap /swapfile
    swapon /swapfile
    grep -q '^/swapfile' /etc/fstab \
        || printf '/swapfile none swap sw 0 0\n' >> /etc/fstab
fi

log "configurando firewall (22, 80, 443)"
ufw allow OpenSSH
ufw allow 80/tcp
ufw allow 443/tcp
ufw allow 443/udp
ufw --force enable

log "creando directorios"
mkdir -p "$REPO_DIR" /etc/autotributo
chown -R "$DEPLOY_USER:$DEPLOY_USER" "$REPO_DIR"
chmod 750 /etc/autotributo

if [[ ! -f /etc/autotributo/duckdns.env ]]; then
    cat > /etc/autotributo/duckdns.env <<'EOF'
DUCKDNS_DOMAIN=autotributo-staging
DUCKDNS_TOKEN=change-me
EOF
    chmod 600 /etc/autotributo/duckdns.env
    log "edita /etc/autotributo/duckdns.env con tu token de DuckDNS"
fi

if [[ -f "${REPO_DIR}/scripts/staging/systemd/autotributo-duckdns.service" ]]; then
    log "instalando timer de DuckDNS"
    install -m 644 "${REPO_DIR}/scripts/staging/systemd/autotributo-duckdns.service" /etc/systemd/system/
    install -m 644 "${REPO_DIR}/scripts/staging/systemd/autotributo-duckdns.timer" /etc/systemd/system/
    systemctl daemon-reload
    systemctl enable --now autotributo-duckdns.timer
else
    log "repositorio aún no clonado; vuelve a ejecutar este script para instalar el timer"
fi

log "host listo"
log "cierra la sesión SSH y vuelve a entrar para usar docker sin sudo"
