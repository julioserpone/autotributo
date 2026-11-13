#!/usr/bin/env bash
# =============================================================================
# Control local de la instancia EC2 de staging.
#
# Se ejecuta desde tu máquina local, no desde la instancia. Usa AWS CLI con el
# perfil `autotributo-staging` (o el que indique AWS_PROFILE).
#
# Uso: ./scripts/staging/ec2-control.sh {start|stop|reboot|status}
#
# La instancia se busca por el tag Name=autotributo-staging, así que no hay que
# hardcodear el Instance ID.
# =============================================================================
set -euo pipefail

PROFILE="${AWS_PROFILE:-autotributo-staging}"
REGION="${AWS_REGION:-us-east-2}"
INSTANCE_NAME="autotributo-staging"
RDS_ID="autotributo-staging-db"

INSTANCE_ID="$(aws ec2 describe-instances \
  --profile "$PROFILE" --region "$REGION" \
  --filters "Name=tag:Name,Values=$INSTANCE_NAME" \
            "Name=instance-state-name,Values=running,stopped,stopping,pending" \
  --query 'Reservations[0].Instances[0].InstanceId' --output text)"

if [[ "$INSTANCE_ID" == "None" || -z "$INSTANCE_ID" ]]; then
  echo "No se encontró la instancia '$INSTANCE_NAME'." >&2
  exit 1
fi

rds_status() {
  aws rds describe-db-instances --db-instance-identifier "$RDS_ID" \
    --profile "$PROFILE" --region "$REGION" \
    --query 'DBInstances[0].DBInstanceStatus' --output text 2>/dev/null || echo "not-found"
}

case "${1:-}" in
  start)
    echo "Encendiendo $INSTANCE_ID..."
    aws ec2 start-instances --instance-ids "$INSTANCE_ID" \
      --profile "$PROFILE" --region "$REGION" >/dev/null
    echo "Esperando a que esté running..."
    aws ec2 wait instance-running --instance-ids "$INSTANCE_ID" \
      --profile "$PROFILE" --region "$REGION"
    NEW_IP="$(aws ec2 describe-instances --instance-ids "$INSTANCE_ID" \
      --profile "$PROFILE" --region "$REGION" \
      --query 'Reservations[0].Instances[0].PublicIpAddress' --output text)"
    echo "Instancia encendida. IP pública: $NEW_IP"
    echo "DuckDNS se actualizará automáticamente en ≤ 5 min."

    # Encender RDS si está apagada
    RDS_ST="$(rds_status)"
    if [[ "$RDS_ST" == "stopped" ]]; then
      echo "Encendiendo RDS $RDS_ID..."
      aws rds start-db-instance --db-instance-identifier "$RDS_ID" \
        --profile "$PROFILE" --region "$REGION" >/dev/null
      echo "Esperando a que la RDS esté disponible..."
      aws rds wait db-instance-available --db-instance-identifier "$RDS_ID" \
        --profile "$PROFILE" --region "$REGION"
      echo "RDS disponible."
    elif [[ "$RDS_ST" == "available" ]]; then
      echo "RDS ya está disponible."
    else
      echo "RDS en estado: $RDS_ST"
    fi
    ;;
  stop)
    # Apagar RDS primero si está disponible
    RDS_ST="$(rds_status)"
    if [[ "$RDS_ST" == "available" ]]; then
      echo "Apagando RDS $RDS_ID..."
      aws rds stop-db-instance --db-instance-identifier "$RDS_ID" \
        --profile "$PROFILE" --region "$REGION" >/dev/null
      # La AWS CLI 2.x no ofrece un waiter `db-instance-stopped`; se hace
      # polling del estado hasta que RDS quede en `stopped`.
      echo "Esperando a que la RDS se detenga..."
      for _ in $(seq 1 90); do
        RDS_ST="$(rds_status)"
        [[ "$RDS_ST" == "stopped" ]] && break
        sleep 10
      done
      if [[ "$(rds_status)" != "stopped" ]]; then
        echo "La RDS no llegó a 'stopped' (estado actual: $(rds_status))." >&2
        exit 1
      fi
      echo "RDS apagada."
    elif [[ "$RDS_ST" == "stopped" ]]; then
      echo "RDS ya está apagada."
    fi

    echo "Apagando $INSTANCE_ID..."
    aws ec2 stop-instances --instance-ids "$INSTANCE_ID" \
      --profile "$PROFILE" --region "$REGION" >/dev/null
    aws ec2 wait instance-stopped --instance-ids "$INSTANCE_ID" \
      --profile "$PROFILE" --region "$REGION"
    echo "Instancia apagada. Ya no se cobra compute ni IPv4."
    ;;
  reboot)
    echo "Reiniciando $INSTANCE_ID..."
    aws ec2 reboot-instances --instance-ids "$INSTANCE_ID" \
      --profile "$PROFILE" --region "$REGION"
    echo "Reinicio solicitado. DuckDNS se actualizará en ≤ 5 min."
    ;;
  status)
    echo "=== EC2 ==="
    aws ec2 describe-instances --instance-ids "$INSTANCE_ID" \
      --profile "$PROFILE" --region "$REGION" \
      --query 'Reservations[0].Instances[0].[State.Name,PublicIpAddress,InstanceType]' \
      --output table
    echo "=== RDS ==="
    aws rds describe-db-instances --db-instance-identifier "$RDS_ID" \
      --profile "$PROFILE" --region "$REGION" \
      --query 'DBInstances[0].[DBInstanceStatus,Endpoint.Address,Endpoint.Port]' \
      --output table
    ;;
  *)
    echo "Uso: $0 {start|stop|reboot|status}" >&2
    exit 1
    ;;
esac
