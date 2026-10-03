#!/usr/bin/env bash
# Smoke test del modulo whatsapp en su forma nativa (systemd + MySQL del host).
# Reemplaza la version basada en Docker Compose tras la migracion (ADR-0016).
set -euo pipefail

INSTANCE="${WHATSAPP_INSTANCE:-zynervox-whatsapp}"
ENV_FILE="${WHATSAPP_ENV_FILE:-/etc/zynervox/$INSTANCE.env}"
SERVICE="$INSTANCE.service"

[[ -f "$ENV_FILE" ]] || { echo "Falta $ENV_FILE" >&2; exit 1; }
command -v curl >/dev/null 2>&1 || { echo "Falta curl" >&2; exit 1; }
command -v mysql >/dev/null 2>&1 || { echo "Falta mysql" >&2; exit 1; }

set -a
# shellcheck disable=SC1090
source "$ENV_FILE"
set +a

base_path="${BASE_PATH%/}"
direct_url="${WHATSAPP_TEST_DIRECT_URL:-http://${HOST}:${PORT}${base_path}}"
proxy_url="${WHATSAPP_TEST_PROXY_URL:-}"
restart_app="${WHATSAPP_TEST_RESTART:-0}"
temp_dir="$(mktemp -d)"
trap 'rm -rf "$temp_dir"' EXIT
cookies="$temp_dir/cookies"

http_code() {
  curl --max-time 15 -sS -o /dev/null -w '%{http_code}' "$@"
}

systemctl is-active --quiet "$SERVICE" || { echo "Servicio no activo: $SERVICE" >&2; exit 1; }

tables="$(MYSQL_PWD="$DB_PASSWORD" mysql -h "$DB_HOST" -u "$DB_USER" -N -B \
  -e "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema='$DB_NAME';")"
[[ "$tables" =~ ^[1-9][0-9]*$ ]] || { echo "Esquema WhatsApp vacío" >&2; exit 1; }

[[ "$(http_code "$direct_url/")" == 200 ]] || { echo "Frontend directo no disponible" >&2; exit 1; }
if [[ -n "$proxy_url" ]]; then
  [[ "$(http_code "${proxy_url%/}/")" == 200 ]] || { echo "Proxy WhatsApp no disponible" >&2; exit 1; }
fi

[[ ${#ZYNERVOX_SSO_SECRET} -ge 32 ]] || { echo "SSO Zynervox no configurado" >&2; exit 1; }
sso_exp="$(( $(date +%s) + 60 ))"
sso_payload="$(printf '{\"user\":\"smoke_sso\",\"name\":\"Smoke SSO\",\"level\":9,\"empresa_id\":1,\"exp\":%s}' "$sso_exp" | openssl base64 -A | tr '+/' '-_' | tr -d '=')"
sso_signature="$(printf '%s' "$sso_payload" | openssl dgst -sha256 -hmac "$ZYNERVOX_SSO_SECRET" -hex | awk '{print $2}')"
sso_code="$(curl --max-time 15 -sS -o "$temp_dir/sso" -c "$temp_dir/sso-cookies" -w '%{http_code}' \
  -H 'Content-Type: application/json' \
  --data "{\"payload\":\"$sso_payload\",\"signature\":\"$sso_signature\"}" \
  "$direct_url/api/sso/zynervox")"
[[ "$sso_code" == 200 ]] && grep -Fq '"username":"zv_smoke_sso"' "$temp_dir/sso" || {
  echo "SSO Zynervox falló ($sso_code)" >&2
  exit 1
}
sso_invalid_code="$(http_code -H 'Content-Type: application/json' \
  --data "{\"payload\":\"$sso_payload\",\"signature\":\"00\"}" \
  "$direct_url/api/sso/zynervox")"
[[ "$sso_invalid_code" == 403 ]] || { echo "SSO aceptó una firma inválida ($sso_invalid_code)" >&2; exit 1; }

login_body="$temp_dir/login"
login_code="$(curl --max-time 15 -sS -o "$login_body" -c "$cookies" -w '%{http_code}' \
  --data-urlencode "username=$INITIAL_ADMIN_USER" \
  --data-urlencode "password=$INITIAL_ADMIN_PASSWORD" \
  "$direct_url/api/login")"
[[ "$login_code" == 200 ]] || { echo "Login WhatsApp falló ($login_code)" >&2; exit 1; }
grep -Fq "\"username\":\"$INITIAL_ADMIN_USER\"" "$login_body" || {
  echo "Login WhatsApp no devolvió un usuario autenticado" >&2
  exit 1
}
me_body="$temp_dir/me"
me_code="$(curl --max-time 15 -sS -o "$me_body" -b "$cookies" -w '%{http_code}' "$direct_url/api/me")"
[[ "$me_code" == 200 ]] && grep -Fq "\"username\":\"$INITIAL_ADMIN_USER\"" "$me_body" || {
  echo "Sesión WhatsApp inválida" >&2
  exit 1
}

socket_body="$temp_dir/socket"
socket_code="$(curl --max-time 15 -sS -o "$socket_body" -b "$cookies" -w '%{http_code}' \
  "$direct_url/socket.io/?EIO=4&transport=polling")"
[[ "$socket_code" == 200 ]] && grep -q '^0{' "$socket_body" || {
  echo "Socket.IO no disponible" >&2
  exit 1
}

webhook_code="$(http_code \
  "$direct_url/webhook/meta?hub.verify_token=smoke-invalid&hub.challenge=1&hub.mode=subscribe")"
[[ "$webhook_code" == 403 ]] || {
  echo "El webhook aceptó un verify_token inválido ($webhook_code)" >&2
  exit 1
}

if [[ "$restart_app" == 1 ]]; then
  systemctl restart "$SERVICE"
  for _ in $(seq 1 60); do
    curl --max-time 5 -fsS "$direct_url/" >/dev/null 2>&1 && break
    sleep 1
  done
  restart_me="$temp_dir/restart-me"
  restart_code="$(curl --max-time 15 -sS -o "$restart_me" -b "$cookies" -w '%{http_code}' "$direct_url/api/me")"
  [[ "$restart_code" == 200 ]] && grep -Fq "\"username\":\"$INITIAL_ADMIN_USER\"" "$restart_me" || {
    echo "La sesión no sobrevivió al reinicio" >&2
    exit 1
  }
fi

MYSQL_PWD="$DB_PASSWORD" mysql -h "$DB_HOST" -u "$DB_USER" "$DB_NAME" \
  -e "DELETE FROM users WHERE username='zv_smoke_sso' AND role='superadmin'" >/dev/null

printf 'WHATSAPP_SMOKE_OK tables=%s login=200 sso=200 sso_invalid=403 session=200 socket=200 webhook_invalid=403 restart=%s\n' \
  "$tables" "$restart_app"
