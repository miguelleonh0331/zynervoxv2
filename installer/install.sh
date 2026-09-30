#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB_ROOT="${WEB_ROOT:-/var/www/html/zynervox}"
ASTERISK_ROOT="${ASTERISK_ROOT:-/etc/asterisk/synervox}"
URL_PATH="${URL_PATH:-/zynervox}"
APPLY_MIGRATIONS=0
DRY_RUN=0
SKIP_PACKAGES=0

for arg in "$@"; do
  case "$arg" in
    --apply-migrations) APPLY_MIGRATIONS=1 ;;
    --dry-run) DRY_RUN=1 ;;
    --skip-packages) SKIP_PACKAGES=1 ;;
    *) echo "Argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

[[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
[[ -f /etc/os-release ]] || { echo "Linux no compatible" >&2; exit 1; }
source /etc/os-release
[[ "${ID:-}" == "ubuntu" ]] || { echo "Solo Ubuntu está soportado" >&2; exit 1; }
DB_CONFIG=/etc/astguiclient.conf
[[ -f /etc/zynervox/astguiclient.conf ]] && DB_CONFIG=/etc/zynervox/astguiclient.conf

if [[ $DRY_RUN -eq 1 ]]; then
  [[ -f "$DB_CONFIG" ]] && integration=available || integration=partial
  echo "DRY_RUN web=$WEB_ROOT asterisk=$ASTERISK_ROOT url=$URL_PATH migrations=$APPLY_MIGRATIONS integration=$integration skip_packages=$SKIP_PACKAGES"
  exit 0
fi

# La web siempre se instala antes de comprobar integraciones opcionales.
install -d -o root -g www-data -m 0750 "$WEB_ROOT"
tar -C "$ROOT/app/web" --exclude='./config/reporting_mirror.json' -cf - . | tar -C "$WEB_ROOT" -xf -
chown -R root:www-data "$WEB_ROOT"
find "$WEB_ROOT" -type d -exec chmod 0750 {} +
find "$WEB_ROOT" -type f -exec chmod 0640 {} +
echo "WEB_INSTALLED root=$WEB_ROOT"

if [[ $SKIP_PACKAGES -eq 0 ]]; then
  export DEBIAN_FRONTEND=noninteractive
  if ! apt-get update || ! apt-get install -y apache2 php libapache2-mod-php php-curl php-mbstring php-mysql \
    php-xml php-zip python3 python3-pymysql python3-num2words; then
    echo "AVISO: archivos web instalados; no se pudieron completar dependencias" >&2
  fi
else
  echo "AVISO: instalación de paquetes omitida por --skip-packages" >&2
fi

if [[ -f "$DB_CONFIG" ]] && command -v asterisk >/dev/null 2>&1 && command -v mysql >/dev/null 2>&1; then
  install -d -o root -g www-data -m 0750 "$ASTERISK_ROOT"
  cp -a "$ROOT/asterisk/synervox/." "$ASTERISK_ROOT/"
  chown -R root:www-data "$ASTERISK_ROOT"
  find "$ASTERISK_ROOT" -type d -exec chmod 0750 {} +
  find "$ASTERISK_ROOT" -type f -exec chmod 0640 {} +
  find "$ASTERISK_ROOT" -type f -name '*.py' -exec chmod 0750 {} +
else
  echo "AVISO: VICIdial/Asterisk no disponible; se omite integración telefónica" >&2
fi

# Bajo DocumentRoot no hace falta crear ni habilitar un Alias.
if [[ "$WEB_ROOT" != "/var/www/html${URL_PATH}" ]] && command -v a2enconf >/dev/null 2>&1; then
  apache_conf=/etc/apache2/conf-available/zynervox.conf
  sed -e "s|__URL_PATH__|$URL_PATH|g" -e "s|__WEB_ROOT__|$WEB_ROOT|g" \
    "$ROOT/installer/apache-zynervox.conf.template" > "$apache_conf"
  a2enconf zynervox >/dev/null
fi

if [[ $APPLY_MIGRATIONS -eq 1 ]]; then
  if [[ -f "$DB_CONFIG" ]]; then
    php "$ROOT/installer/migrate.php" "$WEB_ROOT" "$ASTERISK_ROOT/modules/migrations"
  else
    echo "AVISO: migraciones omitidas; falta configuración de base" >&2
  fi
fi

if command -v apache2ctl >/dev/null 2>&1; then
  if apache2ctl configtest; then
    systemctl reload apache2
  else
    echo "AVISO: Apache tiene errores previos; archivos web conservados sin recargar" >&2
  fi
fi
WEB_ROOT="$WEB_ROOT" bash "$ROOT/installer/check.sh"
echo "INSTALACION_OK web=$WEB_ROOT url_path=$URL_PATH"
