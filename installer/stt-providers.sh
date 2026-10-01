#!/usr/bin/env bash
set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
VENDOR="$ROOT/src/features/stt_providers/vendor"
WEB_ROOT="${WEB_ROOT:-/var/www/html/zynervox}"
WEB_GROUP="${WEB_GROUP:-www-data}"
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=www
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=root
INSTANCE="${STT_INSTANCE:-zynervox-stt}"
DB_NAME="${STT_DB_NAME:-${INSTANCE//-/_}}"
DB_USER="${STT_DB_USER:-${DB_NAME:0:24}}"
CONFIG_ENV="/etc/zynervox/$INSTANCE.env"
CONFIG_PHP="/etc/zynervox/$INSTANCE.php"
WEB_DEST="$WEB_ROOT/modules/admin/stt_providers_app"

[[ "$INSTANCE" =~ ^[a-z0-9][a-z0-9-]{2,40}$ ]] || { echo "STT_INSTANCE inválido" >&2; exit 2; }
[[ "$DB_NAME" =~ ^[a-zA-Z0-9_]+$ && "$DB_USER" =~ ^[a-zA-Z0-9_]+$ ]] || { echo "Nombre de base o usuario inválido" >&2; exit 2; }
[[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }

install -d -o root -g root -m 0755 /etc/zynervox
if [[ -f "$CONFIG_ENV" ]]; then
    # shellcheck disable=SC1090
    source "$CONFIG_ENV"
else
    STT_DB_PASSWORD="$(openssl rand -hex 24)"
    umask 077
    printf 'STT_DB_NAME=%q\nSTT_DB_USER=%q\nSTT_DB_PASSWORD=%q\n' "$DB_NAME" "$DB_USER" "$STT_DB_PASSWORD" > "$CONFIG_ENV"
fi

mysql -e "CREATE DATABASE IF NOT EXISTS \`$STT_DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; CREATE USER IF NOT EXISTS '$STT_DB_USER'@'localhost' IDENTIFIED BY '$STT_DB_PASSWORD'; ALTER USER '$STT_DB_USER'@'localhost' IDENTIFIED BY '$STT_DB_PASSWORD'; GRANT ALL PRIVILEGES ON \`$STT_DB_NAME\`.* TO '$STT_DB_USER'@'localhost'; FLUSH PRIVILEGES;"
MYSQL_PWD="$STT_DB_PASSWORD" mysql -u "$STT_DB_USER" "$STT_DB_NAME" < "$VENDOR/schema.sql"

install -d -o root -g "$WEB_GROUP" -m 0750 "$WEB_DEST"
tar -C "$VENDOR" --exclude='./config/db.php' -cf - . | tar -C "$WEB_DEST" -xf -
chown -R root:"$WEB_GROUP" "$WEB_DEST"
find "$WEB_DEST" -type d -exec chmod 0750 {} +
find "$WEB_DEST" -type f -exec chmod 0640 {} +

cat > "$CONFIG_PHP" <<EOF
<?php
return [
    'host' => 'localhost',
    'database' => '$STT_DB_NAME',
    'user' => '$STT_DB_USER',
    'password' => '$STT_DB_PASSWORD',
    'charset' => 'utf8mb4',
];
EOF
chown root:"$WEB_GROUP" "$CONFIG_PHP"
chmod 0640 "$CONFIG_PHP"
cat > "$WEB_DEST/config/db.php" <<EOF
<?php
return require '$CONFIG_PHP';
EOF
chown root:"$WEB_GROUP" "$WEB_DEST/config/db.php"
chmod 0640 "$WEB_DEST/config/db.php"

echo "STT_PROVIDERS_READY instance=$INSTANCE database=$STT_DB_NAME"
