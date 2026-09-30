#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)"
ENV_FILE="${WHATSAPP_ENV_FILE:-$ROOT/whatsapp/.env}"

[[ -f "$ENV_FILE" ]] || { echo "Falta $ENV_FILE" >&2; exit 1; }
command -v curl >/dev/null 2>&1 || { echo "Falta curl" >&2; exit 1; }
command -v docker >/dev/null 2>&1 || { echo "Falta Docker" >&2; exit 1; }

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

base_path="${WHATSAPP_BASE_PATH%/}"
direct_url="${WHATSAPP_TEST_DIRECT_URL:-http://${WHATSAPP_BIND}:${WHATSAPP_PORT}${base_path}}"
proxy_url="${WHATSAPP_TEST_PROXY_URL:-}"
restart_app="${WHATSAPP_TEST_RESTART:-0}"
temp_dir="$(mktemp -d)"
trap 'rm -rf "$temp_dir"' EXIT
cookies="$temp_dir/cookies"

require_healthy() {
  local container="$1" status
  status="$(docker inspect --format '{{if .State.Health}}{{.State.Health.Status}}{{else}}{{.State.Status}}{{end}}' "$container")"
  [[ "$status" == healthy || "$status" == running ]] || {
    echo "Contenedor no saludable: $container ($status)" >&2
    exit 1
  }
}

http_code() {
  curl --max-time 15 -sS -o /dev/null -w '%{http_code}' "$@"
}

require_healthy zynervox-whatsapp-db
require_healthy zynervox-whatsapp-app

tables="$(docker exec zynervox-whatsapp-db sh -lc \
  'mysql -N -uroot -p"$MYSQL_ROOT_PASSWORD" "$MYSQL_DATABASE" -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE();"')"
[[ "$tables" =~ ^[1-9][0-9]*$ ]] || { echo "Esquema WhatsApp vacío" >&2; exit 1; }

[[ "$(http_code "$direct_url/")" == 200 ]] || { echo "Frontend directo no disponible" >&2; exit 1; }
if [[ -n "$proxy_url" ]]; then
  [[ "$(http_code "${proxy_url%/}/")" == 200 ]] || { echo "Proxy WhatsApp no disponible" >&2; exit 1; }
fi

login_code="$(curl --max-time 15 -sS -o /dev/null -c "$cookies" -w '%{http_code}' \
  --data-urlencode "username=$WHATSAPP_ADMIN_USER" \
  --data-urlencode "password=$WHATSAPP_ADMIN_PASSWORD" \
  "$direct_url/api/login")"
[[ "$login_code" == 200 ]] || { echo "Login WhatsApp falló ($login_code)" >&2; exit 1; }
[[ "$(http_code -b "$cookies" "$direct_url/api/me")" == 200 ]] || { echo "Sesión WhatsApp inválida" >&2; exit 1; }

socket_body="$temp_dir/socket"
socket_code="$(curl --max-time 15 -sS -o "$socket_body" -b "$cookies" -w '%{http_code}' \
  "$direct_url/socket.io/?EIO=4&transport=polling")"
[[ "$socket_code" == 200 ]] && grep -q '^0{' "$socket_body" || {
  echo "Socket.IO no disponible" >&2
  exit 1
}

if [[ "$restart_app" == 1 ]]; then
  docker restart zynervox-whatsapp-app >/dev/null
  for _ in $(seq 1 60); do
    curl --max-time 5 -fsS "$direct_url/" >/dev/null 2>&1 && break
    sleep 1
  done
  [[ "$(http_code -b "$cookies" "$direct_url/api/me")" == 200 ]] || {
    echo "La sesión no sobrevivió al reinicio" >&2
    exit 1
  }
fi

printf 'WHATSAPP_SMOKE_OK tables=%s login=200 session=200 socket=200 restart=%s\n' \
  "$tables" "$restart_app"
