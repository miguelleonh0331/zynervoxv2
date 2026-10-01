#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WHATSAPP_DIR="$ROOT/whatsapp"
ENV_FILE="$WHATSAPP_DIR/.env"

usage() { echo "Uso: $0 init|up|status|credentials|install-proxy|remove-proxy|backup <archivo>|restore <archivo>|down"; }

require_runtime() {
  command -v docker >/dev/null 2>&1 || { echo "Falta Docker" >&2; exit 1; }
  if docker compose version >/dev/null 2>&1; then
    COMPOSE_BIN=(docker compose)
  elif command -v docker-compose >/dev/null 2>&1; then
    COMPOSE_BIN=(docker-compose)
  else
    echo "Falta Docker Compose" >&2; exit 1
  fi
}

compose() {
  "${COMPOSE_BIN[@]}" --env-file "$ENV_FILE" -p "$COMPOSE_PROJECT_NAME" -f "$WHATSAPP_DIR/compose.yml" "$@"
}

generate_env() {
  [[ -f "$ENV_FILE" ]] && return
  command -v openssl >/dev/null 2>&1 || { echo "Falta openssl" >&2; exit 1; }
  local port=3022
  if command -v ss >/dev/null 2>&1; then
    while ss -ltnH | awk '{print $4}' | grep -qE "(^|:)${port}$"; do
      port=$((port + 1))
      [[ $port -le 3099 ]] || { echo "No hay puerto libre entre 3022 y 3099" >&2; exit 1; }
    done
  fi
  umask 077
  local base_path="${WHATSAPP_BASE_PATH_OVERRIDE:-/zynerwabav2}"
  local project_name="${WHATSAPP_COMPOSE_PROJECT_OVERRIDE:-zynervox-whatsapp}"
  cat > "$ENV_FILE" <<EOF
COMPOSE_PROJECT_NAME=$project_name
ZYNERWABA_IMAGE=miguelleonh0331/zynerwabav2:2.1.0-zynervox
WHATSAPP_BIND=127.0.0.1
WHATSAPP_PORT=$port
WHATSAPP_BASE_PATH=$base_path
WHATSAPP_DB_NAME=zynerwabav2
WHATSAPP_DB_USER=zynerwabav2
WHATSAPP_DB_PASSWORD=$(openssl rand -hex 24)
WHATSAPP_DB_ROOT_PASSWORD=$(openssl rand -hex 24)
WHATSAPP_ADMIN_USER=admin
WHATSAPP_ADMIN_PASSWORD=$(openssl rand -hex 12)
WHATSAPP_SESSION_SECRET=$(openssl rand -hex 32)
WHATSAPP_CREDENTIALS_KEY=$(openssl rand -hex 32)
ZYNERVOX_SSO_SECRET=$(openssl rand -hex 32)
ZYNERVOX_EMPRESA_ID=1
EOF
}

ensure_sso_env() {
  command -v openssl >/dev/null 2>&1 || { echo "Falta openssl" >&2; exit 1; }
  umask 077
  grep -q '^ZYNERVOX_SSO_SECRET=' "$ENV_FILE" || printf 'ZYNERVOX_SSO_SECRET=%s\n' "$(openssl rand -hex 32)" >> "$ENV_FILE"
  grep -q '^ZYNERVOX_EMPRESA_ID=' "$ENV_FILE" || printf 'ZYNERVOX_EMPRESA_ID=1\n' >> "$ENV_FILE"
}

load_env() {
  [[ -f "$ENV_FILE" ]] || { echo "Falta $ENV_FILE; ejecute init" >&2; exit 1; }
  set -a
  source "$ENV_FILE"
  set +a
  [[ "$WHATSAPP_PORT" =~ ^[0-9]+$ ]] || { echo "WHATSAPP_PORT inválido" >&2; exit 1; }
  [[ "$WHATSAPP_BASE_PATH" =~ ^/[A-Za-z0-9._/-]+$ ]] || { echo "WHATSAPP_BASE_PATH inválido" >&2; exit 1; }
  [[ "$WHATSAPP_DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || { echo "WHATSAPP_DB_NAME inválido" >&2; exit 1; }
  [[ "$WHATSAPP_DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || { echo "WHATSAPP_DB_USER inválido" >&2; exit 1; }
  [[ "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]+$ ]] || { echo "COMPOSE_PROJECT_NAME inválido" >&2; exit 1; }
}

wait_app() {
  for _ in $(seq 1 60); do
    if curl -fsS "http://127.0.0.1:${WHATSAPP_PORT}${WHATSAPP_BASE_PATH}/" >/dev/null 2>&1; then return; fi
    sleep 2
  done
  echo "Zynerwaba no quedó disponible" >&2
  exit 1
}

sync_initial_admin() {
  compose exec -T app node -e '
const bcrypt = require("bcryptjs");
const mysql = require("mysql2/promise");
(async () => {
  const connection = await mysql.createConnection({
    host: process.env.DB_HOST,
    port: Number(process.env.DB_PORT || 3306),
    user: process.env.DB_USER,
    password: process.env.DB_PASSWORD,
    database: process.env.DB_NAME,
  });
  const hash = bcrypt.hashSync(String(process.env.INITIAL_ADMIN_PASSWORD), 10);
  const [result] = await connection.execute(
    "UPDATE users SET password_hash=?, active=1 WHERE username=? AND role=\"superadmin\"",
    [hash, process.env.INITIAL_ADMIN_USER]
  );
  await connection.end();
  if (result.affectedRows !== 1) throw new Error(`superadmin rows updated: ${result.affectedRows}`);
  console.log("WHATSAPP_ADMIN_SYNCED");
})().catch((error) => { console.error(error.message); process.exit(1); });
'
}

action="${1:-}"
case "$action" in
  init)
    require_runtime; generate_env; ensure_sso_env; load_env
    compose build --pull app
    compose up -d
    wait_app
    sync_initial_admin
    echo "WHATSAPP_READY host=$WHATSAPP_BIND port=$WHATSAPP_PORT path=$WHATSAPP_BASE_PATH"
    ;;
  up) require_runtime; load_env; compose up -d --build; wait_app ;;
  status) require_runtime; load_env; compose ps ;;
  credentials)
    load_env
    printf 'WHATSAPP_ADMIN_USER=%s\nWHATSAPP_ADMIN_PASSWORD=%s\nWHATSAPP_PATH=%s\n' \
      "$WHATSAPP_ADMIN_USER" "$WHATSAPP_ADMIN_PASSWORD" "$WHATSAPP_BASE_PATH"
    ;;
  install-proxy)
    load_env
    web_group=www-data
    getent group "$web_group" >/dev/null || web_group=www
    getent group "$web_group" >/dev/null || web_group=root
    command -v a2enmod >/dev/null 2>&1 && sudo a2enmod proxy proxy_http headers >/dev/null
    proxy_slug="$(printf '%s' "$WHATSAPP_BASE_PATH" | tr -c 'A-Za-z0-9' '-' | sed 's/^-*//;s/-*$//')"
    proxy_name="zynervox-whatsapp-${proxy_slug:-default}"
    if command -v a2enconf >/dev/null 2>&1; then
      proxy_file="/etc/apache2/conf-available/${proxy_name}.conf"
    elif [[ -d /etc/apache2/conf.d ]]; then
      proxy_file="/etc/apache2/conf.d/${proxy_name}.conf"
    else
      echo "No se encontró directorio de configuración Apache" >&2; exit 1
    fi
    sed -e "s|__BASE_PATH__|$WHATSAPP_BASE_PATH|g" -e "s|__PORT__|$WHATSAPP_PORT|g" \
      "$ROOT/installer/apache-whatsapp.conf.template" | \
      sudo tee "$proxy_file" >/dev/null
    command -v a2enconf >/dev/null 2>&1 && sudo a2enconf "$proxy_name" >/dev/null
    sudo apache2ctl configtest
    sudo systemctl reload apache2
    sudo install -d -o root -g "$web_group" -m 0750 /etc/zynervox
    printf 'WHATSAPP_BASE_PATH=%s\nZYNERVOX_SSO_SECRET=%s\nZYNERVOX_EMPRESA_ID=%s\n' \
      "$WHATSAPP_BASE_PATH" "$ZYNERVOX_SSO_SECRET" "$ZYNERVOX_EMPRESA_ID" | \
      sudo tee /etc/zynervox/whatsapp.conf >/dev/null
    sudo chown root:"$web_group" /etc/zynervox/whatsapp.conf
    sudo chmod 0640 /etc/zynervox/whatsapp.conf
    echo "WHATSAPP_PROXY_READY path=$WHATSAPP_BASE_PATH port=$WHATSAPP_PORT"
    ;;
  remove-proxy)
    load_env
    proxy_slug="$(printf '%s' "$WHATSAPP_BASE_PATH" | tr -c 'A-Za-z0-9' '-' | sed 's/^-*//;s/-*$//')"
    proxy_name="zynervox-whatsapp-${proxy_slug:-default}"
    if command -v a2disconf >/dev/null 2>&1; then
      sudo a2disconf "$proxy_name" >/dev/null 2>&1 || true
      sudo rm -f "/etc/apache2/conf-available/${proxy_name}.conf"
    elif [[ -d /etc/apache2/conf.d ]]; then
      sudo rm -f "/etc/apache2/conf.d/${proxy_name}.conf"
    fi
    if command -v apache2ctl >/dev/null 2>&1; then
      sudo rm -f /etc/zynervox/whatsapp.conf
      sudo apache2ctl configtest
      sudo systemctl reload apache2
    fi
    echo "WHATSAPP_PROXY_REMOVED"
    ;;
  backup)
    require_runtime; load_env
    output="${2:-}"
    [[ -n "$output" ]] || { usage; exit 2; }
    umask 077
    compose exec -T db mysqldump -uroot -p"$WHATSAPP_DB_ROOT_PASSWORD" \
      --single-transaction --routines --events --triggers "$WHATSAPP_DB_NAME" | gzip > "$output"
    echo "WHATSAPP_BACKUP_READY file=$output"
    ;;
  restore)
    require_runtime; load_env
    input="${2:-}"
    [[ -f "$input" ]] || { echo "Backup no encontrado: $input" >&2; exit 2; }
    gzip -dc "$input" | compose exec -T db \
      mysql -uroot -p"$WHATSAPP_DB_ROOT_PASSWORD" "$WHATSAPP_DB_NAME"
    echo "WHATSAPP_RESTORE_OK file=$input"
    ;;
  down) require_runtime; load_env; compose down ;;
  *) usage; exit 2 ;;
esac
