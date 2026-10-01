#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WHATSAPP_DIR="$ROOT/whatsapp"
ENV_FILE="$WHATSAPP_DIR/.env"
COMPOSE=(docker compose --env-file "$ENV_FILE" -f "$WHATSAPP_DIR/compose.yml")

usage() { echo "Uso: $0 init|up|status|credentials|install-proxy|remove-proxy|backup <archivo>|restore <archivo>|down"; }

require_runtime() {
  command -v docker >/dev/null 2>&1 || { echo "Falta Docker" >&2; exit 1; }
  docker compose version >/dev/null 2>&1 || { echo "Falta Docker Compose" >&2; exit 1; }
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
  cat > "$ENV_FILE" <<EOF
ZYNERWABA_IMAGE=miguelleonh0331/zynerwabav2:2.0.0@sha256:b6e3ac4ba9115435b151482c6d8c06fbbfe96a572c77cc1e83778c3e68e4c624
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
EOF
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
  "${COMPOSE[@]}" exec -T app node -e '
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
    require_runtime; generate_env; load_env
    "${COMPOSE[@]}" up -d
    wait_app
    sync_initial_admin
    echo "WHATSAPP_READY host=$WHATSAPP_BIND port=$WHATSAPP_PORT path=$WHATSAPP_BASE_PATH"
    ;;
  up) require_runtime; load_env; "${COMPOSE[@]}" up -d; wait_app ;;
  status) require_runtime; load_env; "${COMPOSE[@]}" ps ;;
  credentials)
    load_env
    printf 'WHATSAPP_ADMIN_USER=%s\nWHATSAPP_ADMIN_PASSWORD=%s\nWHATSAPP_PATH=%s\n' \
      "$WHATSAPP_ADMIN_USER" "$WHATSAPP_ADMIN_PASSWORD" "$WHATSAPP_BASE_PATH"
    ;;
  install-proxy)
    load_env
    command -v a2enconf >/dev/null 2>&1 || { echo "Falta Apache" >&2; exit 1; }
    sudo a2enmod proxy proxy_http headers >/dev/null
    sed -e "s|__BASE_PATH__|$WHATSAPP_BASE_PATH|g" -e "s|__PORT__|$WHATSAPP_PORT|g" \
      "$ROOT/installer/apache-whatsapp.conf.template" | \
      sudo tee /etc/apache2/conf-available/zynervox-whatsapp.conf >/dev/null
    sudo a2enconf zynervox-whatsapp >/dev/null
    sudo apache2ctl configtest
    sudo systemctl reload apache2
    sudo install -d -o root -g www-data -m 0750 /etc/zynervox
    printf 'WHATSAPP_BASE_PATH=%s\n' "$WHATSAPP_BASE_PATH" | \
      sudo tee /etc/zynervox/whatsapp.conf >/dev/null
    sudo chown root:www-data /etc/zynervox/whatsapp.conf
    sudo chmod 0640 /etc/zynervox/whatsapp.conf
    echo "WHATSAPP_PROXY_READY path=$WHATSAPP_BASE_PATH port=$WHATSAPP_PORT"
    ;;
  remove-proxy)
    if command -v a2disconf >/dev/null 2>&1; then
      sudo a2disconf zynervox-whatsapp >/dev/null 2>&1 || true
      sudo rm -f /etc/apache2/conf-available/zynervox-whatsapp.conf
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
    "${COMPOSE[@]}" exec -T db mysqldump -uroot -p"$WHATSAPP_DB_ROOT_PASSWORD" \
      --single-transaction --routines --events --triggers "$WHATSAPP_DB_NAME" | gzip > "$output"
    echo "WHATSAPP_BACKUP_READY file=$output"
    ;;
  restore)
    require_runtime; load_env
    input="${2:-}"
    [[ -f "$input" ]] || { echo "Backup no encontrado: $input" >&2; exit 2; }
    gzip -dc "$input" | "${COMPOSE[@]}" exec -T db \
      mysql -uroot -p"$WHATSAPP_DB_ROOT_PASSWORD" "$WHATSAPP_DB_NAME"
    echo "WHATSAPP_RESTORE_OK file=$input"
    ;;
  down) require_runtime; load_env; "${COMPOSE[@]}" down ;;
  *) usage; exit 2 ;;
esac
