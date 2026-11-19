#!/usr/bin/env bash
# =============================================================================
# Ciclo de vida de la EC2 de staging (ADR-0008).
#
# Staging no es 24/7: se crea cuando se va a probar y se destruye al terminar.
# "Destroy" (no "stop") es la única forma de llegar a USD 0: al terminar la
# instancia desaparecen el volumen EBS y la IPv4 pública.
#
# Uso:
#   ./scripts/staging/ec2-control.sh create           # imagen completa (rápido)
#   ./scripts/staging/ec2-control.sh create --source   # build en la instancia
#   ./scripts/staging/ec2-control.sh status
#   ./scripts/staging/ec2-control.sh destroy --yes
#
# Reglas de coste que aplica este script:
#   - Nunca asigna Elastic IP: la subnet asigna una IPv4 pública temporal que
#     se libera al terminar la instancia.
#   - Nunca crea AMI ni snapshot: cada "create" parte de la AMI pública de
#     Ubuntu 24.04 y del código de la imagen `autotributo-staging:staging`.
#   - El volumen raíz es DeleteOnTermination=true y se borra con la instancia.
#   - t3.micro en modo "standard" para no pagar CPU credits extra.
#
# Respaldos locales (fuera del repositorio, permisos 600):
#   ~/.config/autotributo/staging.env    -> .env de Laravel
#   ~/.config/autotributo/duckdns.env    -> token de DuckDNS
# =============================================================================
set -euo pipefail

PROFILE="${AWS_PROFILE:-autotributo-staging}"
REGION="${AWS_REGION:-us-east-2}"
INSTANCE_NAME="${INSTANCE_NAME:-autotributo-staging}"
INSTANCE_TYPE="${INSTANCE_TYPE:-t3.micro}"
KEY_NAME="${KEY_NAME:-autotributo-staging}"
KEY_FILE="${KEY_FILE:-$HOME/.ssh/autotributo-staging.pem}"
SG_ID="${SG_ID:-sg-072f04eac881e39d6}"
SUBNET_ID="${SUBNET_ID:-}"
AVAILABILITY_ZONE="${AVAILABILITY_ZONE:-${REGION}a}"
IAM_PROFILE="${IAM_PROFILE:-autotributo-staging-ec2-profile}"
VOLUME_SIZE="${VOLUME_SIZE:-30}"
SSH_USER="${SSH_USER:-ubuntu}"
APP_DIR="${APP_DIR:-/opt/autotributo}"
REPO_URL="${REPO_URL:-https://github.com/julioserpone/autotributo.git}"
BACKUP_DIR="${BACKUP_DIR:-$HOME/.config/autotributo}"
# Imagen completa de GHCR (código + vendor + assets). `create` la usa por defecto.
APP_IMAGE_DEFAULT="${APP_IMAGE_DEFAULT:-ghcr.io/julioserpone/autotributo-staging:staging}"
# `--source` despliega el código del repositorio sin imagen: solo para depurar
# el build del contenedor en la instancia.
DEPLOY_SOURCE="${DEPLOY_SOURCE:-0}"
APP_IMAGE_PATH=""
APP_IMAGE_TAG=""
AMI_OWNER="${AMI_OWNER:-099720109477}"
AMI_NAME_FILTER="${AMI_NAME_FILTER:-ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*}"

export AWS_PROFILE="$PROFILE"
export AWS_REGION="$REGION"
export AWS_DEFAULT_REGION="$REGION"

SSH_OPTS=(
    -i "$KEY_FILE"
    -o BatchMode=yes
    -o ConnectTimeout=10
    -o StrictHostKeyChecking=accept-new
    -o UserKnownHostsFile="$BACKUP_DIR/known_hosts"
)

log() { printf '[ec2] %s\n' "$*"; }
fail() { printf '[ec2] ERROR: %s\n' "$*" >&2; exit 1; }

find_instance() {
    aws ec2 describe-instances \
        --filters "Name=tag:Name,Values=$INSTANCE_NAME" \
                  "Name=instance-state-name,Values=pending,running,stopping,stopped" \
        --query 'Reservations[0].Instances[0].InstanceId' \
        --output text 2>/dev/null || true
}

instance_field() {
    aws ec2 describe-instances --instance-ids "$1" \
        --query "Reservations[0].Instances[0].$2" --output text
}

resolve_ami() {
    aws ec2 describe-images --owners "$AMI_OWNER" \
        --filters "Name=name,Values=$AMI_NAME_FILTER" \
                  "Name=state,Values=available" \
        --query 'sort_by(Images,&CreationDate)[-1].ImageId' \
        --output text
}

resolve_subnet() {
    if [[ -n "$SUBNET_ID" ]]; then
        printf '%s' "$SUBNET_ID"
        return
    fi

    aws ec2 describe-subnets \
        --filters "Name=default-for-az,Values=true" \
                  "Name=availability-zone,Values=$AVAILABILITY_ZONE" \
        --query 'Subnets[0].SubnetId' \
        --output text
}

staging_hostname() {
    sed -n 's/^STAGING_HOSTNAME=//p' "$BACKUP_DIR/staging.env" \
        | tail -n1 | tr -d '"' | tr -d "'"
}

parse_app_image() {
    local remainder tag
    remainder="${1#ghcr.io/}"
    tag="latest"
    if [[ "$remainder" == *:* ]]; then
        tag="${remainder##*:}"
        remainder="${remainder%%:*}"
    fi
    APP_IMAGE_PATH="$remainder"
    APP_IMAGE_TAG="$tag"
}

# Imagen completa efectiva: la del respaldo o la de GHCR por defecto.
staging_app_image() {
    local image
    image="$(sed -n 's/^APP_IMAGE=//p' "$BACKUP_DIR/staging.env" \
        | tail -n1 | tr -d '"' | tr -d "'")"
    printf '%s' "${image:-$APP_IMAGE_DEFAULT}"
}

wait_ssh() {
    local ip="$1"
    for _ in $(seq 1 60); do
        if ssh "${SSH_OPTS[@]}" "$SSH_USER@$ip" 'echo ok' >/dev/null 2>&1; then
            log "SSH disponible en $ip"
            return 0
        fi
        sleep 5
    done
    fail "SSH no respondió en $ip tras 5 minutos"
}

wait_dns() {
    local ip="$1" hostname resolved
    hostname="$(staging_hostname)"
    [[ -n "$hostname" ]] || fail "falta STAGING_HOSTNAME en el .env respaldado"
    for _ in $(seq 1 60); do
        resolved="$(dig +short "$hostname" @1.1.1.1 2>/dev/null | tail -n1 || true)"
        if [[ "$resolved" == "$ip" ]]; then
            log "DuckDNS resuelve ${hostname} -> $ip"
            return 0
        fi
        sleep 5
    done
    fail "DuckDNS no apunta a $ip (último valor: ${resolved:-<vacío>})"
}

backup_remote() {
    local ip="$1"
    mkdir -p "$BACKUP_DIR"
    chmod 700 "$BACKUP_DIR"

    log "respaldando .env en $BACKUP_DIR/staging.env"
    scp "${SSH_OPTS[@]}" "$SSH_USER@$ip:$APP_DIR/.env" "$BACKUP_DIR/staging.env"
    chmod 600 "$BACKUP_DIR/staging.env"

    log "respaldando duckdns.env en $BACKUP_DIR/duckdns.env"
    ssh "${SSH_OPTS[@]}" "$SSH_USER@$ip" \
        'sudo cat /etc/autotributo/duckdns.env' > "$BACKUP_DIR/duckdns.env"
    chmod 600 "$BACKUP_DIR/duckdns.env"

    [[ -s "$BACKUP_DIR/duckdns.env" ]] \
        || fail "el respaldo de duckdns.env está vacío"
}

bootstrap_host() {
    local ip="$1"

    log "copiando .env y duckdns.env a la instancia"
    scp "${SSH_OPTS[@]}" "$BACKUP_DIR/staging.env" \
        "$SSH_USER@$ip:/tmp/staging.env"
    scp "${SSH_OPTS[@]}" "$BACKUP_DIR/duckdns.env" \
        "$SSH_USER@$ip:/tmp/duckdns.env"

    log "clonando repositorio y colocando configuración"
    # Las variables se expanden en el cliente a propósito.
    # shellcheck disable=SC2087
    ssh "${SSH_OPTS[@]}" "$SSH_USER@$ip" bash -s <<REMOTE
set -euo pipefail
sudo mkdir -p /etc/autotributo
sudo install -m 600 -o root -g root /tmp/duckdns.env /etc/autotributo/duckdns.env
rm -f /tmp/duckdns.env

if [[ ! -d "$APP_DIR/.git" ]]; then
    sudo mkdir -p "$APP_DIR"
    sudo chown "$SSH_USER:$SSH_USER" "$APP_DIR"
    git clone "$REPO_URL" "$APP_DIR"
fi

install -m 600 /tmp/staging.env "$APP_DIR/.env"
rm -f /tmp/staging.env
REMOTE

    log "provisionando el host (Docker, swap, UFW, DuckDNS)"
    # shellcheck disable=SC2029
    ssh "${SSH_OPTS[@]}" "$SSH_USER@$ip" \
        "sudo $APP_DIR/scripts/staging/provision-host.sh"

    log "actualizando DuckDNS antes de emitir el certificado"
    ssh "${SSH_OPTS[@]}" "$SSH_USER@$ip" \
        'sudo systemctl start autotributo-duckdns.service'
    wait_dns "$ip"

    log "desplegando la aplicación"
    # shellcheck disable=SC2029
    ssh "${SSH_OPTS[@]}" "$SSH_USER@$ip" \
        "cd $APP_DIR && APP_IMAGE='$(staging_app_image)' ./scripts/staging/deploy.sh"
}

cmd_create() {
    [[ -f "$KEY_FILE" ]] || fail "no existe la clave SSH: $KEY_FILE"

    case "${1:-}" in
        --source) DEPLOY_SOURCE=1 ;;
        "") ;;
        *) fail "argumento desconocido para create: $1 (usa --source o nada)" ;;
    esac

    mkdir -p "$BACKUP_DIR"
    chmod 700 "$BACKUP_DIR"
    [[ -f "$BACKUP_DIR/staging.env" ]] \
        || fail "falta $BACKUP_DIR/staging.env (respaldo del .env de staging)"

    local app_image
    app_image=""
    if [[ "$DEPLOY_SOURCE" != "1" ]]; then
        app_image="$(staging_app_image)"
        # Una imagen privada rompería el bootstrap a mitad: la instancia no tiene
        # credenciales de GHCR. Se comprueba que el manifiesto sea anónimo.
        local anonymous_token status
        parse_app_image "$app_image"
        anonymous_token="$(curl -sS --max-time 20 \
            "https://ghcr.io/token?scope=repository:${APP_IMAGE_PATH}:pull&service=ghcr.io" \
            2>/dev/null | sed -n 's/.*"token"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' || true)"
        status="$(curl -s -o /dev/null -w '%{http_code}' --max-time 20 \
            -H "Authorization: Bearer ${anonymous_token}" \
            -H 'Accept: application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.docker.distribution.manifest.v2+json' \
            "https://ghcr.io/v2/${APP_IMAGE_PATH}/manifests/${APP_IMAGE_TAG}" \
            2>/dev/null || true)"
        if [[ "$status" != "200" ]]; then
            fail "${app_image} no se puede descargar sin login (HTTP ${status:-000}): haz el paquete público en GitHub Packages, o usa 'create --source'"
        fi
        log "imagen ${app_image} descargable sin login"
    else
        log "create --source: sin imagen; la instancia solo construye el contenedor"
    fi

    local existing
    existing="$(find_instance)"
    if [[ -n "$existing" && "$existing" != "None" ]]; then
        fail "ya existe la instancia $existing; ejecuta 'destroy --yes' antes de crear otra"
    fi

    [[ -f "$BACKUP_DIR/duckdns.env" ]] \
        || fail "falta $BACKUP_DIR/duckdns.env (respaldo del token DuckDNS)"
    [[ -n "$(staging_hostname)" ]] \
        || fail "falta STAGING_HOSTNAME en $BACKUP_DIR/staging.env"

    local ami subnet
    ami="$(resolve_ami)"
    [[ "$ami" == ami-* ]] || fail "no se pudo resolver la AMI de Ubuntu 24.04"
    subnet="$(resolve_subnet)"
    [[ "$subnet" == subnet-* ]] || fail "no se pudo resolver la subnet por defecto"

    log "creando $INSTANCE_TYPE desde $ami en $AVAILABILITY_ZONE"
    local args=(
        --image-id "$ami"
        --instance-type "$INSTANCE_TYPE"
        --key-name "$KEY_NAME"
        --security-group-ids "$SG_ID"
        --subnet-id "$subnet"
        --credit-specification 'CpuCredits=standard'
        --metadata-options 'HttpTokens=required,HttpEndpoint=enabled'
        --block-device-mappings "[{\"DeviceName\":\"/dev/sda1\",\"Ebs\":{\"VolumeSize\":$VOLUME_SIZE,\"VolumeType\":\"gp3\",\"DeleteOnTermination\":true,\"Encrypted\":true}}]"
        --tag-specifications "ResourceType=instance,Tags=[{Key=Name,Value=$INSTANCE_NAME}]"
    )
    if [[ -n "$IAM_PROFILE" ]]; then
        args+=(--iam-instance-profile "Name=$IAM_PROFILE")
    fi

    local instance_id
    instance_id="$(aws ec2 run-instances "${args[@]}" \
        --query 'Instances[0].InstanceId' --output text)"
    [[ "$instance_id" == i-* ]] || fail "no se pudo crear la instancia"

    log "instancia $instance_id creada; esperando estado running"
    aws ec2 wait instance-running --instance-ids "$instance_id"

    local ip
    ip="$(instance_field "$instance_id" PublicIpAddress)"
    [[ -n "$ip" && "$ip" != "None" ]] || fail "la instancia no tiene IP pública"
    ssh-keygen -R "$ip" -f "$BACKUP_DIR/known_hosts" >/dev/null 2>&1 || true
    log "IP pública temporal: $ip (sin Elastic IP)"

    wait_ssh "$ip"
    bootstrap_host "$ip"

    log "staging listo: https://$(staging_hostname)"
    log "coste mientras esté encendida: ~USD 0.019/hora"
    log "al terminar: ./scripts/staging/ec2-control.sh destroy --yes"
}

cmd_destroy() {
    local confirm="${1:-}"
    [[ "$confirm" == "--yes" ]] \
        || fail "destroy borra la instancia y su disco. Repite con: $0 destroy --yes"

    local instance_id
    instance_id="$(find_instance)"
    if [[ -z "$instance_id" || "$instance_id" == "None" ]]; then
        log "no hay instancia; ya estás en USD 0"
        return 0
    fi

    local state volume_id ip
    state="$(instance_field "$instance_id" State.Name)"
    volume_id="$(instance_field "$instance_id" 'BlockDeviceMappings[0].Ebs.VolumeId')"

    if [[ "$state" == "stopping" ]]; then
        log "esperando a que la instancia termine de detenerse"
        aws ec2 wait instance-stopped --instance-ids "$instance_id"
        state="stopped"
    elif [[ "$state" == "pending" ]]; then
        aws ec2 wait instance-running --instance-ids "$instance_id"
        state="running"
    fi

    if [[ "$state" == "stopped" ]]; then
        log "la instancia está detenida; se enciende para respaldar el .env"
        aws ec2 start-instances --instance-ids "$instance_id" >/dev/null
        aws ec2 wait instance-running --instance-ids "$instance_id"
        state="running"
    fi

    if [[ "$state" == "running" ]]; then
        ip="$(instance_field "$instance_id" PublicIpAddress)"
        if [[ -n "$ip" && "$ip" != "None" ]]; then
            wait_ssh "$ip"
            backup_remote "$ip"
        else
            fail "la instancia no tiene IP pública; no se pudo respaldar el .env"
        fi
    else
        fail "estado inesperado '$state'; no se destruye sin respaldo"
    fi

    log "terminando $instance_id (volumen $volume_id)"
    aws ec2 terminate-instances --instance-ids "$instance_id" >/dev/null
    aws ec2 wait instance-terminated --instance-ids "$instance_id"

    if [[ -n "$volume_id" && "$volume_id" != "None" ]]; then
        local volume_state="unknown"
        for _ in $(seq 1 24); do
            volume_state="$(aws ec2 describe-volumes --volume-ids "$volume_id" \
                --query 'Volumes[0].State' --output text 2>/dev/null || printf 'deleted')"
            if [[ "$volume_state" == "deleted" || "$volume_state" == "None" ]]; then
                break
            fi
            sleep 5
        done
        if [[ "$volume_state" == "deleted" || "$volume_state" == "None" ]]; then
            log "volumen $volume_id eliminado"
        else
            log "ADVERTENCIA: el volumen $volume_id sigue en estado '$volume_state'"
            log "bórralo manualmente para llegar a USD 0"
        fi
    fi

    log "instancia terminada: sin EC2, sin EBS y sin IPv4. Coste AWS: USD 0"
}

cmd_status() {
    local instance_id
    instance_id="$(find_instance)"

    if [[ -z "$instance_id" || "$instance_id" == "None" ]]; then
        log "no hay instancia de staging. Coste de cómputo/almacenamiento: USD 0"
        log "para levantarla: ./scripts/staging/ec2-control.sh create"
        return 0
    fi

    aws ec2 describe-instances --instance-ids "$instance_id" \
        --query 'Reservations[0].Instances[0].{Id:InstanceId,State:State.Name,Type:InstanceType,AZ:Placement.AvailabilityZone,IP:PublicIpAddress,Launched:LaunchTime}' \
        --output table

    log "coste mientras esté encendida: ~USD 0.019/hora"
    log "para volver a USD 0: ./scripts/staging/ec2-control.sh destroy --yes"
}

case "${1:-}" in
    create)  shift; cmd_create "${1:-}" ;;
    destroy) shift; cmd_destroy "${1:-}" ;;
    status)  cmd_status ;;
    *)
        printf 'Uso: %s {create [--source]|destroy --yes|status}\n' "$0" >&2
        exit 1
        ;;
esac
