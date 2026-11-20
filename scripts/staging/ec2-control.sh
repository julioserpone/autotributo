#!/usr/bin/env bash
# =============================================================================
# Ciclo de vida de la EC2 de staging (ADR-0008).
#
# Staging no es 24/7: se crea cuando se va a probar y se destruye al terminar.
# "Destroy" (no "stop") es la única forma de llegar a USD 0: al terminar la
# instancia desaparecen el volumen EBS y la IPv4 pública.
#
# Uso:
#   ./scripts/staging/ec2-control.sh create                    # imagen completa (rápido)
#   ./scripts/staging/ec2-control.sh create --source           # build en la instancia
#   ./scripts/staging/ec2-control.sh create --recreate-db      # recrea la BD (migrate:fresh --seed)
#   ./scripts/staging/ec2-control.sh create --source --recreate-db  # build + BD recreada
#   ./scripts/staging/ec2-control.sh status
#   ./scripts/staging/ec2-control.sh destroy --yes
#
# La consola usa un panel de dos secciones (scripts/lib/dashboard.sh):
#   1. Etapas: cada paso con su estado (pendiente, en curso, OK, aviso, falló).
#   2. En curso: el comando que se está ejecutando y sus últimas líneas.
# Todo cabe en una pantalla: no hay scroll mientras el proceso corre. Al salir
# se imprime un resumen en texto plano y, si algo falló, la ruta del log
# completo. Con stdout redirigido (CI, `| tee`) el panel cae solo a texto plano;
# `--inline` lo fuerza.
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

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=../lib/dashboard.sh disable=SC1091
source "$SCRIPT_DIR/../lib/dashboard.sh"

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
AMI_OWNER="${AMI_OWNER:-099720109477}"
AMI_NAME_FILTER="${AMI_NAME_FILTER:-ubuntu/images/hvm-ssd-gp3/ubuntu-noble-24.04-amd64-server-*}"

export AWS_PROFILE="$PROFILE"
export AWS_REGION="$REGION"
export AWS_DEFAULT_REGION="$REGION"

SSH_OPTS=(
    -i "$KEY_FILE"
    -o BatchMode=yes
    # 20s: una instancia recién arrancada puede tardar en aceptar la primera
    # conexión mientras cloud-init termina. Con 10s el create abortaba por un
    # timeout transitorio.
    -o ConnectTimeout=20
    -o ServerAliveInterval=15
    -o ServerAliveCountMax=4
    -o StrictHostKeyChecking=accept-new
    -o UserKnownHostsFile="$BACKUP_DIR/known_hosts"
)

log() { printf '[ec2] %s\n' "$*"; }
fail() { dash_fail "$*"; }

# ---------------------------------------------------------------------------
# Consultas a AWS (devuelven datos por stdout: se usan con dash_capture)
# ---------------------------------------------------------------------------

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

# HTTP status del manifiesto de GHCR sin credenciales. 200 = descargable.
ghcr_manifest_status() {
    local image="$1" remainder tag token
    remainder="${image#ghcr.io/}"
    tag="latest"
    if [[ "$remainder" == *:* ]]; then
        tag="${remainder##*:}"
        remainder="${remainder%%:*}"
    fi

    token="$(curl -sS --max-time 20 \
        "https://ghcr.io/token?scope=repository:${remainder}:pull&service=ghcr.io" \
        2>/dev/null | sed -n 's/.*"token"[[:space:]]*:[[:space:]]*"\([^"]*\)".*/\1/p' || true)"

    curl -s -o /dev/null -w '%{http_code}' --max-time 20 \
        -H "Authorization: Bearer ${token}" \
        -H 'Accept: application/vnd.oci.image.index.v1+json, application/vnd.docker.distribution.manifest.list.v2+json, application/vnd.docker.distribution.manifest.v2+json' \
        "https://ghcr.io/v2/${remainder}/manifests/${tag}" \
        2>/dev/null || true
    printf '\n'
}

staging_hostname() {
    sed -n 's/^STAGING_HOSTNAME=//p' "$BACKUP_DIR/staging.env" \
        | tail -n1 | tr -d '"' | tr -d "'"
}

# Imagen completa efectiva: la del respaldo o la de GHCR por defecto.
staging_app_image() {
    local image
    image="$(sed -n 's/^APP_IMAGE=//p' "$BACKUP_DIR/staging.env" \
        | tail -n1 | tr -d '"' | tr -d "'")"
    printf '%s' "${image:-$APP_IMAGE_DEFAULT}"
}

# ---------------------------------------------------------------------------
# Esperas con progreso en el panel (corren en el proceso principal, así que
# dash_note sí actualiza la pantalla)
# ---------------------------------------------------------------------------

wait_instance_state() {
    local id="$1" want="$2" i state
    for i in $(seq 1 60); do
        if state="$(instance_field "$id" State.Name 2>/dev/null)"; then
            if [[ "$state" == "$want" ]]; then
                dash_note "instancia $id en estado $want"
                return 0
            fi
        elif [[ "$want" == "terminated" ]]; then
            dash_note "instancia $id ya no existe"
            return 0
        else
            state="desconocido"
        fi
        dash_note "Esperando '$want': $id está en '${state}' (${i}/60)"
        dash_wait 5
    done
    return 1
}

wait_ssh() {
    local ip="$1" i
    for i in $(seq 1 18); do
        if ssh "${SSH_OPTS[@]}" "$SSH_USER@$ip" 'echo ok' >/dev/null 2>&1; then
            dash_note "SSH disponible en $ip"
            return 0
        fi
        dash_note "Esperando SSH en $ip (intento ${i}/18)"
        dash_wait 5
    done
    return 1
}

# Reintenta un comando ssh solo cuando falla el transporte (código 255). Un
# código distinto viene del comando remoto y no se reintenta. Se usa dentro de
# dash_run, así que los mensajes van al panel en vez de a dash_note.
ssh_retry() {
    local tries="${1:-5}" i rc=0
    shift
    for (( i = 1; i <= tries; i++ )); do
        rc=0
        "$@" || rc=$?
        (( rc == 0 )) && return 0
        (( rc != 255 )) && return "$rc"
        if (( i < tries )); then
            printf 'reintento %d/%d: SSH no respondió (código %d), esperando 10s\n' \
                "$i" "$tries" "$rc"
            sleep 10
        fi
    done
    return "$rc"
}

# Igual que ssh_retry, pero para comandos que reciben el script por stdin: el
# pipe se reconstruye en cada intento (un heredoc se consumiría en el primero).
ssh_retry_stdin() {
    local tries="${1:-5}" script="$2" i rc=0
    shift 2
    for (( i = 1; i <= tries; i++ )); do
        rc=0
        printf '%s\n' "$script" | "$@" || rc=$?
        (( rc == 0 )) && return 0
        (( rc != 255 )) && return "$rc"
        if (( i < tries )); then
            printf 'reintento %d/%d: SSH no respondió (código %d), esperando 10s\n' \
                "$i" "$tries" "$rc"
            sleep 10
        fi
    done
    return "$rc"
}

wait_dns() {
    local ip="$1" hostname resolved i
    hostname="$(staging_hostname)"
    [[ -n "$hostname" ]] || fail "falta STAGING_HOSTNAME en el .env respaldado"
    for i in $(seq 1 60); do
        resolved="$(dig +short "$hostname" @1.1.1.1 2>/dev/null | tail -n1 || true)"
        if [[ "$resolved" == "$ip" ]]; then
            dash_note "DuckDNS resuelve ${hostname} -> $ip"
            return 0
        fi
        dash_note "Esperando DuckDNS: ${hostname} -> $ip (${i}/60, valor ${resolved:-vacío})"
        dash_wait 5
    done
    fail "DuckDNS no apunta a $ip (último valor: ${resolved:-<vacío>})"
}

# Verificación externa: DNS público + TLS + /up desde esta máquina.
verify_health() {
    local host="$1" i code
    for i in $(seq 1 12); do
        code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 10 \
            "https://${host}/up" 2>/dev/null || true)"
        if [[ "$code" == "200" ]]; then
            dash_note "https://${host}/up respondió 200 con TLS válido"
            return 0
        fi
        dash_note "Verificando https://${host}/up (intento ${i}/12, HTTP ${code:-000})"
        dash_wait 5
    done
    return 1
}

# ---------------------------------------------------------------------------
# Pasos remotos (se ejecutan dentro de dash_run para ver el comando en vivo)
# ---------------------------------------------------------------------------

remote_put_config() {
    local ip="$1"
    scp "${SSH_OPTS[@]}" "$BACKUP_DIR/staging.env" \
        "$SSH_USER@$ip:/tmp/staging.env"
    scp "${SSH_OPTS[@]}" "$BACKUP_DIR/duckdns.env" \
        "$SSH_USER@$ip:/tmp/duckdns.env"
}

remote_put_demo() {
    local ip="$1" tmp
    tmp="$(mktemp)"
    chmod 600 "$tmp"
    printf 'DEMO_RUC=%q\nDEMO_SRI_PASSWORD=%q\n' "$DEMO_RUC" "$DEMO_SRI_PASSWORD" >"$tmp"
    ssh_retry 5 scp "${SSH_OPTS[@]}" "$tmp" \
        "$SSH_USER@$ip:/tmp/autotributo-demo.env" \
        || { rm -f "$tmp"; return 1; }
    rm -f "$tmp"
}

remote_install_script() {
    # Las variables se expanden en el cliente a propósito.
    # shellcheck disable=SC2087
    cat <<REMOTE
set -euo pipefail
sudo mkdir -p /etc/autotributo
sudo install -m 600 -o root -g root /tmp/duckdns.env /etc/autotributo/duckdns.env
rm -f /tmp/duckdns.env

if [[ ! -d "$APP_DIR/.git" ]]; then
    sudo mkdir -p "$APP_DIR"
    sudo chown "$SSH_USER:$SSH_USER" "$APP_DIR"
    git clone --depth 1 "$REPO_URL" "$APP_DIR"
fi

install -m 600 /tmp/staging.env "$APP_DIR/.env"
rm -f /tmp/staging.env
REMOTE
}

remote_install_config() {
    local ip="$1" script
    script="$(remote_install_script)"
    ssh_retry_stdin 5 "$script" ssh "${SSH_OPTS[@]}" "$SSH_USER@$ip" bash -s
}

backup_remote() {
    local ip="$1" tmp_env tmp_dns
    mkdir -p "$BACKUP_DIR"
    chmod 700 "$BACKUP_DIR"

    # Se descarga a archivos temporales y solo se reemplaza el respaldo si
    # ambos llegaron con contenido. Así un fallo a mitad no destruye el token
    # que ya estaba respaldado (antes la redirección lo truncaba a 0 bytes).
    tmp_env="$BACKUP_DIR/.staging.env.new"
    tmp_dns="$BACKUP_DIR/.duckdns.env.new"
    rm -f "$tmp_env" "$tmp_dns"

    if ! scp "${SSH_OPTS[@]}" "$SSH_USER@$ip:$APP_DIR/.env" "$tmp_env"; then
        rm -f "$tmp_env" "$tmp_dns"
        printf 'no se pudo descargar %s/.env\n' "$APP_DIR" >&2
        return 1
    fi

    if ! ssh "${SSH_OPTS[@]}" "$SSH_USER@$ip" \
        'sudo cat /etc/autotributo/duckdns.env' >"$tmp_dns"; then
        rm -f "$tmp_env" "$tmp_dns"
        printf 'no se pudo leer /etc/autotributo/duckdns.env (¿sudo sin permisos o archivo ausente?)\n' >&2
        return 1
    fi

    if [[ ! -s "$tmp_env" ]]; then
        rm -f "$tmp_env" "$tmp_dns"
        printf '%s/.env llegó vacío\n' "$APP_DIR" >&2
        return 1
    fi
    if [[ ! -s "$tmp_dns" ]]; then
        rm -f "$tmp_env" "$tmp_dns"
        printf '/etc/autotributo/duckdns.env llegó vacío\n' >&2
        return 1
    fi

    chmod 600 "$tmp_env" "$tmp_dns"
    mv -f "$tmp_env" "$BACKUP_DIR/staging.env"
    mv -f "$tmp_dns" "$BACKUP_DIR/duckdns.env"
    return 0
}

# ---------------------------------------------------------------------------
# Comandos
# ---------------------------------------------------------------------------

cmd_create() {
    DB_RECREATE="${DB_RECREATE:-0}"
    DEMO_RUC="${DEMO_RUC:-}"
    DEMO_SRI_PASSWORD="${DEMO_SRI_PASSWORD:-}"
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --source) DEPLOY_SOURCE=1; shift ;;
            --recreate-db) DB_RECREATE=1; shift ;;
            --inline) DASH_MODE=inline; shift ;;
            --ruc)
                [[ $# -ge 2 ]] || fail "--ruc requiere un valor"
                DEMO_RUC="$2"; shift 2 ;;
            --ruc=*) DEMO_RUC="${1#--ruc=}"; shift ;;
            --password)
                [[ $# -ge 2 ]] || fail "--password requiere un valor"
                DEMO_SRI_PASSWORD="$2"; shift 2 ;;
            --password=*) DEMO_SRI_PASSWORD="${1#--password=}"; shift ;;
            -h | --help) usage; return 0 ;;
            *) fail "argumento desconocido para create: $1 (usa --source, --recreate-db, --ruc, --password o nada)" ;;
        esac
    done

    if [[ -n "$DEMO_RUC" || -n "$DEMO_SRI_PASSWORD" ]]; then
        [[ -n "$DEMO_RUC" && -n "$DEMO_SRI_PASSWORD" ]] \
            || fail "--ruc y --password deben indicarse juntos"
        [[ "$DEMO_RUC" =~ ^[0-9]{13}$ ]] \
            || fail "--ruc debe tener 13 dígitos"
        [[ "$DEMO_SRI_PASSWORD" != *$'\n'* && "$DEMO_SRI_PASSWORD" != *$'\r'* ]] \
            || fail "--password no puede contener saltos de línea"
        (( ${#DEMO_SRI_PASSWORD} >= 6 )) \
            || fail "--password debe tener al menos 6 caracteres"
    fi

    dash_init "Autotributo staging" "create"
    dash_plan \
        "Verificar imagen y entorno" \
        "Crear instancia EC2" \
        "Provisionar host (Docker, swap, UFW, DuckDNS)" \
        "Desplegar la aplicación" \
        "Verificar staging (/up y TLS)" \
        "Listo"

    # --- 1/6 -------------------------------------------------------------
    dash_stage 1
    [[ -f "$KEY_FILE" ]] || fail "no existe la clave SSH: $KEY_FILE"
    [[ -s "$BACKUP_DIR/staging.env" ]] \
        || fail "falta o está vacío $BACKUP_DIR/staging.env (respaldo del .env de staging)"
    [[ -s "$BACKUP_DIR/duckdns.env" ]] \
        || fail "falta o está vacío $BACKUP_DIR/duckdns.env: escribe DUCKDNS_DOMAIN y DUCKDNS_TOKEN (duckdns.org) en ese archivo"
    mkdir -p "$BACKUP_DIR"
    chmod 700 "$BACKUP_DIR"

    local hostname
    hostname="$(staging_hostname)"
    [[ -n "$hostname" ]] || fail "falta STAGING_HOSTNAME en $BACKUP_DIR/staging.env"

    if [[ -n "${DEMO_RUC:-}" ]]; then
        # El seeder cifra la credencial SRI con SRI_CREDENTIALS_KEY. Si el
        # respaldo no la tiene, se genera una y se persiste para no perderla
        # en recreaciones futuras.
        if ! grep -qE '^SRI_CREDENTIALS_KEY=.+' "$BACKUP_DIR/staging.env"; then
            local key
            key="$(openssl rand -base64 32)"
            printf '\nSRI_CREDENTIALS_KEY=%s\n' "$key" >>"$BACKUP_DIR/staging.env"
            chmod 600 "$BACKUP_DIR/staging.env"
            dash_note "SRI_CREDENTIALS_KEY generada y guardada en el respaldo (600)"
        fi
    fi

    local app_image="" status
    if [[ "$DEPLOY_SOURCE" != "1" ]]; then
        app_image="$(staging_app_image)"
        # Una imagen privada rompería el bootstrap a mitad: la instancia no tiene
        # credenciales de GHCR. Se comprueba que el manifiesto sea anónimo.
        dash_capture status "Comprobando el manifiesto de $app_image" \
            ghcr_manifest_status "$app_image" || true
        [[ "$status" == "200" ]] \
            || fail "${app_image} no se puede descargar sin login (HTTP ${status:-000}): haz el paquete público en GitHub Packages, o usa 'create --source'"
        dash_note "imagen ${app_image} descargable sin login"
    else
        dash_note "create --source: sin imagen; la instancia construye el contenedor"
    fi

    local existing
    existing="$(find_instance)"
    if [[ -n "$existing" && "$existing" != "None" ]]; then
        fail "ya existe la instancia $existing; ejecuta 'destroy --yes' antes de crear otra"
    fi

    local ami subnet
    dash_capture_as ami \
        "aws ec2 describe-images --owners $AMI_OWNER --filters Name=name,Values=$AMI_NAME_FILTER" \
        "Resolviendo la AMI de Ubuntu 24.04" resolve_ami
    [[ "$ami" == ami-* ]] || fail "no se pudo resolver la AMI de Ubuntu 24.04"
    dash_capture_as subnet \
        "aws ec2 describe-subnets --filters Name=default-for-az,Values=true Name=availability-zone,Values=$AVAILABILITY_ZONE" \
        "Resolviendo la subnet por defecto en $AVAILABILITY_ZONE" resolve_subnet
    [[ "$subnet" == subnet-* ]] || fail "no se pudo resolver la subnet por defecto"
    dash_stage_done

    # --- 2/6 -------------------------------------------------------------
    dash_stage 2
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

    dash_note "Creando $INSTANCE_TYPE desde $ami en $AVAILABILITY_ZONE"
    local instance_id
    dash_capture instance_id "aws ec2 run-instances" \
        aws ec2 run-instances "${args[@]}" \
        --query 'Instances[0].InstanceId' --output text \
        || fail "no se pudo crear la instancia (revisa la salida del panel)"
    [[ "$instance_id" == i-* ]] || fail "no se pudo crear la instancia"
    dash_meta "instancia $instance_id · $AVAILABILITY_ZONE"

    wait_instance_state "$instance_id" running \
        || fail "la instancia $instance_id no llegó a 'running'"

    local ip
    dash_capture_as ip \
        "aws ec2 describe-instances --instance-ids $instance_id --query Reservations[0].Instances[0].PublicIpAddress" \
        "Leyendo la IP pública" instance_field "$instance_id" PublicIpAddress || true
    [[ -n "$ip" && "$ip" != "None" ]] || fail "la instancia no tiene IP pública"
    ssh-keygen -R "$ip" -f "$BACKUP_DIR/known_hosts" >/dev/null 2>&1 || true
    dash_meta "instancia $instance_id · $ip · $AVAILABILITY_ZONE"
    dash_note "IP pública temporal: $ip (sin Elastic IP)"
    dash_stage_done

    # --- 3/6 -------------------------------------------------------------
    dash_stage 3
    dash_note "Esperando SSH en $ip"
    wait_ssh "$ip" || fail "SSH no respondió en $ip (revisa el security group $SG_ID)"

    dash_run_as "scp staging.env duckdns.env $SSH_USER@$ip:/tmp/" \
        "Copiando .env y token de DuckDNS" remote_put_config "$ip" \
        || fail "no se pudo copiar la configuración a la instancia"
    if [[ -n "${DEMO_RUC:-}" ]]; then
        dash_run_as "scp demo.env $SSH_USER@$ip:/tmp/" \
            "Copiando credenciales de prueba (RUC ${DEMO_RUC})" remote_put_demo "$ip" \
            || fail "no se pudo copiar las credenciales de prueba"
    fi
    dash_run_as "ssh $SSH_USER@$ip bash -s" \
        "Instalando configuración y repositorio en el host" \
        remote_install_config "$ip" \
        || fail "no se pudo instalar la configuración (instancia $instance_id sigue encendida: destroy --yes y reintenta)"
    dash_run_as "ssh $SSH_USER@$ip sudo $APP_DIR/scripts/staging/provision-host.sh" \
        "Provisionando el host (Docker, swap, UFW, DuckDNS)" \
        ssh_retry 5 ssh "${SSH_OPTS[@]}" "$SSH_USER@$ip" \
        "sudo $APP_DIR/scripts/staging/provision-host.sh" \
        || fail "provision-host.sh falló en la instancia (sigue encendida: $instance_id; destroy --yes y reintenta)"
    dash_run_as "ssh $SSH_USER@$ip sudo systemctl start autotributo-duckdns.service" \
        "Activando el servicio DuckDNS" \
        ssh_retry 5 ssh "${SSH_OPTS[@]}" "$SSH_USER@$ip" \
        'sudo systemctl start autotributo-duckdns.service' \
        || fail "no se pudo activar autotributo-duckdns.service"
    wait_dns "$ip"
    dash_stage_done

    # --- 4/6 -------------------------------------------------------------
    dash_stage 4
    # shellcheck disable=SC2029
    dash_run_as "ssh $SSH_USER@$ip 'cd $APP_DIR && ./scripts/staging/deploy.sh'" \
        "Desplegando la aplicación (deploy.sh)" \
        ssh_retry 5 ssh "${SSH_OPTS[@]}" "$SSH_USER@$ip" \
        "cd $APP_DIR && DB_RECREATE='$DB_RECREATE' APP_IMAGE='$(staging_app_image)' DEMO_ENV_FILE='/tmp/autotributo-demo.env' ./scripts/staging/deploy.sh" \
        || fail "el deploy falló (instancia $instance_id sigue encendida; revisa la salida del panel)"
    dash_stage_done

    # --- 5/6 -------------------------------------------------------------
    dash_stage 5
    verify_health "$hostname" \
        || fail "https://$hostname/up no respondió 200 (DNS, TLS o aplicación)"
    dash_stage_done

    # --- 6/6 -------------------------------------------------------------
    dash_stage 6
    dash_note "staging listo en https://$hostname"
    dash_finish ok "https://$hostname · ~USD 0.019/h mientras esté encendida"
}

cmd_destroy() {
    local confirm=""
    while [[ $# -gt 0 ]]; do
        case "$1" in
            --yes) confirm=1; shift ;;
            --inline) DASH_MODE=inline; shift ;;
            -h | --help) usage; return 0 ;;
            *) fail "argumento desconocido para destroy: $1" ;;
        esac
    done
    [[ "$confirm" == "1" ]] \
        || fail "destroy borra la instancia y su disco. Repite con: $0 destroy --yes"

    dash_init "Autotributo staging" "destroy"
    dash_plan \
        "Localizar la instancia" \
        "Respaldar el .env" \
        "Terminar instancia y volumen" \
        "Listo"

    # --- 1/4 -------------------------------------------------------------
    dash_stage 1
    local instance_id
    dash_capture_as instance_id \
        "aws ec2 describe-instances --filters Name=tag:Name,Values=$INSTANCE_NAME" \
        "Buscando instancias de staging" find_instance || true
    if [[ -z "$instance_id" || "$instance_id" == "None" ]]; then
        dash_note "no hay instancia: ya estás en USD 0"
        dash_stage_done
        dash_finish ok "no había instancia de staging · coste AWS USD 0"
    fi

    local state volume_id
    dash_capture_as state \
        "aws ec2 describe-instances --instance-ids $instance_id --query State.Name" \
        "Leyendo el estado de $instance_id" instance_field "$instance_id" State.Name || true
    dash_capture_as volume_id \
        "aws ec2 describe-instances --instance-ids $instance_id --query BlockDeviceMappings[0].Ebs.VolumeId" \
        "Leyendo el volumen EBS" instance_field "$instance_id" 'BlockDeviceMappings[0].Ebs.VolumeId' || true
    dash_meta "instancia $instance_id · volumen ${volume_id:-?}"

    if [[ "$state" == "stopping" ]]; then
        dash_note "esperando a que termine de detenerse"
        wait_instance_state "$instance_id" stopped || fail "no llegó a 'stopped'"
        state="stopped"
    elif [[ "$state" == "pending" ]]; then
        dash_note "esperando a que termine de arrancar"
        wait_instance_state "$instance_id" running || fail "no llegó a 'running'"
        state="running"
    fi

    if [[ "$state" == "stopped" ]]; then
        dash_note "la instancia está detenida: se enciende para respaldar el .env"
        dash_run "aws ec2 start-instances" \
            aws ec2 start-instances --instance-ids "$instance_id" \
            || fail "no se pudo encender la instancia"
        wait_instance_state "$instance_id" running || fail "no llegó a 'running'"
        state="running"
    fi

    [[ "$state" == "running" ]] \
        || fail "estado inesperado '$state'; no se destruye sin respaldo"
    dash_stage_done

    # --- 2/4 -------------------------------------------------------------
    dash_stage 2
    local ip
    dash_capture_as ip \
        "aws ec2 describe-instances --instance-ids $instance_id --query PublicIpAddress" \
        "Leyendo la IP pública" instance_field "$instance_id" PublicIpAddress || true
    [[ -n "$ip" && "$ip" != "None" ]] \
        || fail "la instancia no tiene IP pública; no se pudo respaldar el .env"
    dash_meta "instancia $instance_id · $ip"

    dash_note "Esperando SSH en $ip"
    if ! wait_ssh "$ip"; then
        dash_stage_warn "SSH no respondió en 60s: se destruye sin respaldo nuevo"
        dash_note "SSH no disponible: se destruye sin respaldo"
    elif dash_run_as "scp $SSH_USER@$ip:$APP_DIR/.env ${BACKUP_DIR/#$HOME/~}/staging.env" \
        "Respaldando .env y duckdns.env" backup_remote "$ip"; then
        dash_note "respaldo en $BACKUP_DIR"
        dash_stage_done
    else
        dash_stage_warn "no se pudo respaldar: se conserva el respaldo anterior"
        dash_note "no se pudo respaldar: se destruye sin respaldo"
    fi

    # --- 3/4 -------------------------------------------------------------
    dash_stage 3
    dash_run "aws ec2 terminate-instances" \
        aws ec2 terminate-instances --instance-ids "$instance_id" \
        || fail "no se pudo terminar la instancia"
    wait_instance_state "$instance_id" terminated \
        || fail "la instancia no llegó a 'terminated'"

    if [[ -n "$volume_id" && "$volume_id" != "None" ]]; then
        local volume_state="unknown" i
        for i in $(seq 1 24); do
            volume_state="$(aws ec2 describe-volumes --volume-ids "$volume_id" \
                --query 'Volumes[0].State' --output text 2>/dev/null || printf 'deleted')"
            if [[ "$volume_state" == "deleted" || "$volume_state" == "None" ]]; then
                break
            fi
            dash_note "esperando a que el volumen $volume_id se borre (${i}/24)"
            dash_wait 5
        done
        if [[ "$volume_state" == "deleted" || "$volume_state" == "None" ]]; then
            dash_note "volumen $volume_id eliminado"
        else
            dash_stage_warn "el volumen $volume_id sigue en '$volume_state': bórralo para llegar a USD 0"
            dash_note "el volumen $volume_id sigue en '$volume_state': bórralo para llegar a USD 0"
        fi
    fi
    dash_stage_done

    # --- 4/4 -------------------------------------------------------------
    dash_stage 4
    dash_note "sin EC2, sin EBS y sin IPv4"
    dash_finish ok "instancia terminada · coste AWS USD 0"
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

usage() {
    cat <<EOF
Uso: $0 <comando> [opciones]

  create   [--source] [--recreate-db] [--ruc RUC --password PASS] [--inline]   crea la instancia y despliega
  destroy  --yes [--inline]                        respalda el .env y lo borra todo
  status                                           estado, IP y coste

El panel de consola ocupa una sola pantalla; --inline lo desactiva.
EOF
}

case "${1:-}" in
    create) shift; cmd_create "$@" ;;
    destroy) shift; cmd_destroy "$@" ;;
    status) shift; cmd_status ;;
    -h | --help) usage ;;
    *)
        usage >&2
        exit 1
        ;;
esac
