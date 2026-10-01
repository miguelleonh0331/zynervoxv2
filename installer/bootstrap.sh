#!/usr/bin/env bash
set -euo pipefail

REPOSITORY_URL="${ZYNERVOX_REPOSITORY_URL:-https://github.com/miguelleonh0331/zynervoxv2.git}"
REF="${ZYNERVOX_REF:-release/zynervoxv2-deploy-test}"
SOURCE_DIR="${ZYNERVOX_SOURCE_DIR:-/opt/zynervoxv2-deploy-test-source}"
WEB_ROOT="${WEB_ROOT:-/var/www/html/zynervoxv2-deploy-test}"
URL_PATH="${URL_PATH:-/zynervoxv2-deploy-test}"
ASTERISK_ROOT="${ASTERISK_ROOT:-/etc/asterisk/synervox-deploy-test}"

[[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
command -v git >/dev/null 2>&1 || { echo "Falta git" >&2; exit 1; }
[[ ! -e "$SOURCE_DIR" ]] || { echo "La ruta fuente ya existe: $SOURCE_DIR" >&2; exit 1; }
[[ ! -e "$WEB_ROOT" ]] || { echo "La ruta web ya existe: $WEB_ROOT" >&2; exit 1; }

git clone --depth 1 --branch "$REF" "$REPOSITORY_URL" "$SOURCE_DIR"

export WEB_ROOT URL_PATH ASTERISK_ROOT
export WHATSAPP_BASE_PATH_OVERRIDE="${WHATSAPP_BASE_PATH_OVERRIDE:-${URL_PATH}-whatsapp}"
export WHATSAPP_COMPOSE_PROJECT_OVERRIDE="${WHATSAPP_COMPOSE_PROJECT_OVERRIDE:-zynervoxv2-deploy-test}"

exec "$SOURCE_DIR/installer/install.sh" --skip-packages --install-docker --with-whatsapp
