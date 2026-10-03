#!/usr/bin/env bash
# BD propia de zynervox para el login administrativo (zynervox_core /
# zynervox_users), separada de `asterisk`/vicidial_users para no competir ni
# depender de datos VICIdial compartidos entre instalaciones. Se reinstala
# (DROP+CREATE) en cada corrida: la credencial admin queda siempre conocida.
set -euo pipefail

CONFIG_DIR=/etc/zynervox
CORE_CONF="$CONFIG_DIR/zynervox-core.conf"
DB_NAME="${ZYNERVOX_CORE_DB:-zynervox_core}"
DB_USER="${ZYNERVOX_CORE_DB_USER:-zynervox_core}"
DB_PASSWORD="$(openssl rand -hex 24)"
ADMIN_USER="${ZYNERVOX_CORE_ADMIN_USER:-admin}"
ADMIN_PASSWORD="${ZYNERVOX_CORE_ADMIN_PASSWORD:-persepolis}"

if ! command -v mysql >/dev/null 2>&1; then
  echo "AVISO: mysql no disponible; se omite zynervox_core (login propio)" >&2
  exit 0
fi

mysql -e "DROP DATABASE IF EXISTS \`$DB_NAME\`; \
CREATE DATABASE \`$DB_NAME\` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci; \
CREATE USER IF NOT EXISTS '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD'; \
ALTER USER '$DB_USER'@'localhost' IDENTIFIED BY '$DB_PASSWORD'; \
GRANT ALL PRIVILEGES ON \`$DB_NAME\`.* TO '$DB_USER'@'localhost'; \
FLUSH PRIVILEGES;"

MYSQL_PWD="$DB_PASSWORD" mysql -u "$DB_USER" "$DB_NAME" <<SQL
CREATE TABLE zynervox_users (
  user varchar(60) NOT NULL PRIMARY KEY,
  pass varchar(255) NOT NULL,
  full_name varchar(100) NOT NULL DEFAULT 'Administrador',
  user_level int NOT NULL DEFAULT 9,
  active char(1) NOT NULL DEFAULT 'Y'
) ENGINE=InnoDB;
INSERT INTO zynervox_users (user, pass, full_name, user_level, active)
VALUES ('$ADMIN_USER', '$ADMIN_PASSWORD', 'Administrador', 9, 'Y');
SQL

install -d -o root -g root -m 0755 "$CONFIG_DIR"
umask 077
cat > "$CORE_CONF" <<EOF
CORE_DB_server => 127.0.0.1
CORE_DB_database => $DB_NAME
CORE_DB_user => $DB_USER
CORE_DB_pass => $DB_PASSWORD
CORE_DB_port => 3306
EOF
chown root:www-data "$CORE_CONF" 2>/dev/null || true
chmod 0640 "$CORE_CONF"

echo "ZYNERVOX_CORE_READY database=$DB_NAME admin_user=$ADMIN_USER"
