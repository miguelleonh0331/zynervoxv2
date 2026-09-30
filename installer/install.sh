#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
WEB_ROOT="${WEB_ROOT:-/var/www/html/zynervox}"
ASTERISK_ROOT="${ASTERISK_ROOT:-/etc/asterisk/synervox}"
URL_PATH="${URL_PATH:-/zynervox}"
APPLY_MIGRATIONS=0
DRY_RUN=0

for arg in "$@"; do
  case "$arg" in
    --apply-migrations) APPLY_MIGRATIONS=1 ;;
    --dry-run) DRY_RUN=1 ;;
    *) echo "Argumento desconocido: $arg" >&2; exit 2 ;;
  esac
done

[[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
[[ -f /etc/os-release ]] || { echo "Linux no compatible" >&2; exit 1; }
source /etc/os-release
[[ "${ID:-}" == "ubuntu" ]] || { echo "Solo Ubuntu está soportado" >&2; exit 1; }
[[ -f /etc/astguiclient.conf ]] || { echo "Falta /etc/astguiclient.conf" >&2; exit 1; }

if [[ $DRY_RUN -eq 1 ]]; then
  echo "DRY_RUN web=$WEB_ROOT asterisk=$ASTERISK_ROOT url=$URL_PATH migrations=$APPLY_MIGRATIONS"
  exit 0
fi

export DEBIAN_FRONTEND=noninteractive
apt-get update
apt-get install -y apache2 php libapache2-mod-php php-curl php-mbstring php-mysql \
  php-xml php-zip python3 python3-pymysql python3-num2words rsync

install -d -o root -g www-data -m 0750 "$WEB_ROOT" "$ASTERISK_ROOT"
rsync -a --exclude='config/reporting_mirror.json' "$ROOT/app/web/" "$WEB_ROOT/"
rsync -a "$ROOT/asterisk/synervox/" "$ASTERISK_ROOT/"
chown -R root:www-data "$WEB_ROOT" "$ASTERISK_ROOT"
find "$WEB_ROOT" "$ASTERISK_ROOT" -type d -exec chmod 0750 {} +
find "$WEB_ROOT" "$ASTERISK_ROOT" -type f -exec chmod 0640 {} +
find "$ASTERISK_ROOT" -type f -name '*.py' -exec chmod 0750 {} +

apache_conf=/etc/apache2/conf-available/zynervox.conf
sed -e "s|__URL_PATH__|$URL_PATH|g" -e "s|__WEB_ROOT__|$WEB_ROOT|g" \
  "$ROOT/installer/apache-zynervox.conf.template" > "$apache_conf"
a2enconf zynervox >/dev/null
apache2ctl configtest

if [[ $APPLY_MIGRATIONS -eq 1 ]]; then
  php "$ROOT/installer/migrate.php" "$WEB_ROOT" "$ASTERISK_ROOT/modules/migrations"
fi

systemctl reload apache2
WEB_ROOT="$WEB_ROOT" "$ROOT/installer/check.sh"
echo "INSTALACION_OK url_path=$URL_PATH"
