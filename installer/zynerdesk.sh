#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
ZYNERDESK_DIR="$ROOT/zynerdesk"
ENV_FILE="$ZYNERDESK_DIR/.env"
ZYNERDESK_DEFAULT_IMAGE="ghcr.io/miguelleonh0331/synervox-remoteo@sha256:7836bcdecba9514c4c0156790cb0e375f9e42d7a9cec97816628ea50057ef50c"

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
  "${COMPOSE_BIN[@]}" --env-file "$ENV_FILE" -p "$COMPOSE_PROJECT_NAME" -f "$ZYNERDESK_DIR/compose.yml" "$@"
}

generate_env() {
  [[ -f "$ENV_FILE" ]] && return
  command -v openssl >/dev/null 2>&1 || { echo "Falta openssl" >&2; exit 1; }
  local port=4100
  if command -v ss >/dev/null 2>&1; then
    while ss -ltnH | awk '{print $4}' | grep -qE "(^|:)${port}$"; do
      port=$((port + 1))
      [[ $port -le 4199 ]] || { echo "No hay puerto libre entre 4100 y 4199" >&2; exit 1; }
    done
  fi
  umask 077
  local base_path="${ZYNERDESK_BASE_PATH_OVERRIDE:-/zynerdesk}"
  local project_name="${ZYNERDESK_COMPOSE_PROJECT_OVERRIDE:-zynervox-zynerdesk}"
  cat > "$ENV_FILE" <<EOF
COMPOSE_PROJECT_NAME=$project_name
ZYNERDESK_IMAGE=$ZYNERDESK_DEFAULT_IMAGE
ZYNERDESK_BIND_HOST=127.0.0.1
ZYNERDESK_PORT=$port
ZYNERDESK_BASE_PATH=$base_path
ZYNERDESK_DB_NAME=syner_remoteo
ZYNERDESK_DB_USER=syner_remoteo
ZYNERDESK_DB_PASSWORD=$(openssl rand -hex 24)
ZYNERDESK_DB_ROOT_PASSWORD=$(openssl rand -hex 24)
ZYNERDESK_ADMIN_USER=admin
ZYNERDESK_ADMIN_PASSWORD=$(openssl rand -hex 12)
ZYNERVOX_SSO_SECRET=$(openssl rand -hex 32)
ZYNERDESK_COOKIE_SECURE=0
ZYNERDESK_COOKIE_SAME_SITE=Lax
EOF
}

ensure_sso_env() {
  [[ -f "$ENV_FILE" ]] || return
  grep -q '^ZYNERVOX_SSO_SECRET=' "$ENV_FILE" || {
    umask 077
    printf 'ZYNERVOX_SSO_SECRET=%s\n' "$(openssl rand -hex 32)" >> "$ENV_FILE"
  }
}

sync_image_env() {
  [[ -f "$ENV_FILE" ]] || return
  local temp_file="${ENV_FILE}.tmp.$$"
  awk -v image="$ZYNERDESK_DEFAULT_IMAGE" '
    /^ZYNERDESK_IMAGE=/ { print "ZYNERDESK_IMAGE=" image; found=1; next }
    { print }
    END { if (!found) print "ZYNERDESK_IMAGE=" image }
  ' "$ENV_FILE" > "$temp_file"
  chmod 0600 "$temp_file"
  mv -f "$temp_file" "$ENV_FILE"
}

load_env() {
  [[ -f "$ENV_FILE" ]] || { echo "Falta $ENV_FILE; ejecute init" >&2; exit 1; }
  set -a
  source "$ENV_FILE"
  set +a
  [[ "$ZYNERDESK_PORT" =~ ^[0-9]+$ ]] || { echo "ZYNERDESK_PORT inválido" >&2; exit 1; }
  [[ "$ZYNERDESK_BASE_PATH" =~ ^/[A-Za-z0-9._/-]+$ ]] || { echo "ZYNERDESK_BASE_PATH inválido" >&2; exit 1; }
  [[ "$ZYNERDESK_DB_NAME" =~ ^[A-Za-z0-9_]+$ ]] || { echo "ZYNERDESK_DB_NAME inválido" >&2; exit 1; }
  [[ "$ZYNERDESK_DB_USER" =~ ^[A-Za-z0-9_]+$ ]] || { echo "ZYNERDESK_DB_USER inválido" >&2; exit 1; }
  [[ "$COMPOSE_PROJECT_NAME" =~ ^[a-z0-9][a-z0-9_-]+$ ]] || { echo "COMPOSE_PROJECT_NAME inválido" >&2; exit 1; }
}

wait_app() {
  for _ in $(seq 1 60); do
    if curl -fsS "http://127.0.0.1:${ZYNERDESK_PORT}/login.html" >/dev/null 2>&1; then return; fi
    sleep 2
  done
  echo "Zynerdesk no quedó disponible" >&2
  exit 1
}

action="${1:-}"
case "$action" in
  init)
    require_runtime; generate_env; ensure_sso_env; sync_image_env; load_env
    compose pull app
    compose up -d --force-recreate
    wait_app
    echo "ZYNERDESK_READY host=$ZYNERDESK_BIND_HOST port=$ZYNERDESK_PORT path=$ZYNERDESK_BASE_PATH"
    ;;
  up) require_runtime; ensure_sso_env; sync_image_env; load_env; compose pull app; compose up -d; wait_app ;;
  status) require_runtime; load_env; compose ps ;;
  credentials)
    load_env
    printf 'ZYNERDESK_ADMIN_USER=%s\nZYNERDESK_ADMIN_PASSWORD=%s\nZYNERDESK_PATH=%s\n' \
      "$ZYNERDESK_ADMIN_USER" "$ZYNERDESK_ADMIN_PASSWORD" "$ZYNERDESK_BASE_PATH"
    ;;
  install-proxy)
    ensure_sso_env; load_env
    web_group=www-data
    getent group "$web_group" >/dev/null || web_group=www
    getent group "$web_group" >/dev/null || web_group=root
    command -v a2enmod >/dev/null 2>&1 && sudo a2enmod proxy proxy_http proxy_wstunnel headers >/dev/null
    proxy_slug="$(printf '%s' "$ZYNERDESK_BASE_PATH" | tr -c 'A-Za-z0-9' '-' | sed 's/^-*//;s/-*$//')"
    proxy_name="zynervox-zynerdesk-${proxy_slug:-default}"
    if command -v a2enconf >/dev/null 2>&1; then
      proxy_file="/etc/apache2/conf-available/${proxy_name}.conf"
    elif [[ -d /etc/apache2/conf.d ]]; then
      proxy_file="/etc/apache2/conf.d/${proxy_name}.conf"
    else
      echo "No se encontró directorio de configuración Apache" >&2; exit 1
    fi
    sed -e "s|__BASE_PATH__|$ZYNERDESK_BASE_PATH|g" -e "s|__PORT__|$ZYNERDESK_PORT|g" \
      "$ROOT/installer/apache-zynerdesk.conf.template" | \
      sudo tee "$proxy_file" >/dev/null
    command -v a2enconf >/dev/null 2>&1 && sudo a2enconf "$proxy_name" >/dev/null
    sudo apache2ctl configtest
    sudo systemctl reload apache2
    sudo install -d -o root -g "$web_group" -m 0750 /etc/zynervox
    printf 'ZYNERDESK_BASE_PATH=%s\nZYNERDESK_PORT=%s\nZYNERVOX_SSO_SECRET=%s\n' \
      "$ZYNERDESK_BASE_PATH" "$ZYNERDESK_PORT" "$ZYNERVOX_SSO_SECRET" | sudo tee /etc/zynervox/zynerdesk.conf >/dev/null
    sudo chown root:"$web_group" /etc/zynervox/zynerdesk.conf
    sudo chmod 0640 /etc/zynervox/zynerdesk.conf
    echo "ZYNERDESK_PROXY_READY path=$ZYNERDESK_BASE_PATH port=$ZYNERDESK_PORT"
    ;;
  remove-proxy)
    load_env
    proxy_slug="$(printf '%s' "$ZYNERDESK_BASE_PATH" | tr -c 'A-Za-z0-9' '-' | sed 's/^-*//;s/-*$//')"
    proxy_name="zynervox-zynerdesk-${proxy_slug:-default}"
    if command -v a2disconf >/dev/null 2>&1; then
      sudo a2disconf "$proxy_name" >/dev/null 2>&1 || true
      sudo rm -f "/etc/apache2/conf-available/${proxy_name}.conf"
    elif [[ -d /etc/apache2/conf.d ]]; then
      sudo rm -f "/etc/apache2/conf.d/${proxy_name}.conf"
    fi
    if command -v apache2ctl >/dev/null 2>&1; then
      sudo rm -f /etc/zynervox/zynerdesk.conf
      sudo apache2ctl configtest
      sudo systemctl reload apache2
    fi
    echo "ZYNERDESK_PROXY_REMOVED"
    ;;
  backup)
    require_runtime; load_env
    output="${2:-}"
    [[ -n "$output" ]] || { usage; exit 2; }
    umask 077
    compose exec -T db mysqldump -uroot -p"$ZYNERDESK_DB_ROOT_PASSWORD" \
      --single-transaction --routines --events --triggers "$ZYNERDESK_DB_NAME" | gzip > "$output"
    echo "ZYNERDESK_BACKUP_READY file=$output"
    ;;
  restore)
    require_runtime; load_env
    input="${2:-}"
    [[ -f "$input" ]] || { echo "Backup no encontrado: $input" >&2; exit 2; }
    gzip -dc "$input" | compose exec -T db \
      mysql -uroot -p"$ZYNERDESK_DB_ROOT_PASSWORD" "$ZYNERDESK_DB_NAME"
    echo "ZYNERDESK_RESTORE_OK file=$input"
    ;;
  down) require_runtime; load_env; compose down ;;
  *) usage; exit 2 ;;
esac
