#!/usr/bin/env bash
# Apunta el front al túnel de desarrollo (Expose y similares) y reinicia Vite
# para que Laravel genere las rutas de los assets con esa URL pública. nginx
# reenvía /@vite, /@vite-hmr, /resources y /node_modules al contenedor de Vite,
# así que todo queda en el mismo origen: sin CORS ni permisos de red local.
#
# Uso:
#   scripts/tunel.sh https://2du5dqrofe.sharedwithexpose.com
#   scripts/tunel.sh --off
#
# El robot (Lambda) se reapunta aparte; el script imprime el comando.
set -euo pipefail

raiz="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$raiz"

url="${1:-}"
if [[ -z "$url" ]]; then
    echo "Uso: $0 <https://tu-tunel> | --off" >&2
    exit 2
fi

if [[ "$url" == "--off" ]]; then
    url=""
elif [[ ! "$url" =~ ^https?:// ]]; then
    echo "La URL debe empezar por http:// o https://" >&2
    exit 2
fi
url="${url%/}"

python3 - "$url" <<'PY'
import re
import sys
from pathlib import Path

url = sys.argv[1]
ruta = Path(".env")
texto = ruta.read_text()
linea = f"VITE_TUNNEL_URL={url}"

if re.search(r"^VITE_TUNNEL_URL=.*$", texto, flags=re.M):
    texto = re.sub(r"^VITE_TUNNEL_URL=.*$", linea, texto, flags=re.M)
else:
    texto = texto.rstrip("\n") + "\n" + linea + "\n"

# El callback del robot comparte el túnel: si hay URL, se actualiza también.
if url:
    callback = f"SRI_ROBOT_CALLBACK_URL={url}/api/v1/webhooks/sri-robot"
    if re.search(r"^SRI_ROBOT_CALLBACK_URL=.*$", texto, flags=re.M):
        texto = re.sub(r"^SRI_ROBOT_CALLBACK_URL=.*$", callback, texto, flags=re.M)

ruta.write_text(texto)
print(f"VITE_TUNNEL_URL={url or '(vacío)'}")
PY

echo "== reiniciando vite =="
docker compose up -d --force-recreate vite >/dev/null
docker exec autotributo-web nginx -s reload >/dev/null 2>&1 || true

echo "== esperando a vite =="
puerto="$(grep -E '^VITE_PORT=' .env | tail -1 | cut -d= -f2 || true)"
puerto="${puerto:-5173}"
for _ in $(seq 1 30); do
    if curl -sS -o /dev/null -m 3 "http://localhost:${puerto}/@vite/client"; then
        echo "listo: assets en ${url:-localhost}"
        break
    fi
    sleep 3
done

if [[ -n "$url" ]]; then
    host="${url#*://}"
    host="${host%%/*}"
    echo
    echo "Falta reapuntar el robot (Lambda) con:"
    echo "  SRI_CALLBACK_URL=${url}/api/v1/webhooks/sri-robot"
    echo "  SRI_CALLBACK_ALLOWED_HOSTS=${host%%:*}"
fi
