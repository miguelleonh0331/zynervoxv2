#!/usr/bin/env bash
set -euo pipefail

# Zypad: detector de preanswer (STT + reglas) empaquetado en Docker
# (miguelleonh0331/zypad-vosk). A diferencia de farm/whatsapp/zynerdesk/stt
# (nativos, systemd), este modulo SI requiere Docker porque asi se distribuye
# el servicio. Se instala una sola vez por VPS (sin $INSTANCE variable): el
# nombre de contenedor y el puerto son fijos a proposito (ver zynervox-deploy
# para el porque).
#
# El puerto se publica en la IP LAN del host (no solo localhost): otros VPS
# de la misma red le mandan audio para transcribir. La API key se genera aqui
# y se muestra en el panel web (Servicios > Zypad) para copiarla a esos otros
# servidores.

INSTANCE="zynervox-zypad"
IMAGE="${ZYPAD_IMAGE:-miguelleonh0331/zypad-vosk:0.5.0}"
PORT="${ZYPAD_PORT:-8767}"
DATA_DIR="/var/lib/$INSTANCE/data"
CONFIG_ENV="/etc/zynervox/$INSTANCE.env"
CONFIG_PHP="/etc/zynervox/$INSTANCE.php"
WEB_GROUP="${WEB_GROUP:-www-data}"
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=www
getent group "$WEB_GROUP" >/dev/null || WEB_GROUP=root

[[ $EUID -eq 0 ]] || { echo "Ejecutar como root" >&2; exit 1; }
command -v docker >/dev/null 2>&1 || { echo "Zypad requiere Docker instalado (reinstale con --install-docker)" >&2; exit 1; }

# Prioriza una IP privada real (RFC1918) para publicar el puerto. Si el host
# no tiene ninguna (solo IP publica), cae a 0.0.0.0 con aviso explicito --
# mismo riesgo ya documentado para el zypad-vosk de docker_converxa.
detect_bind_ip() {
    local ip
    for ip in $(hostname -I 2>/dev/null); do
        case "$ip" in
            10.*|172.1[6-9].*|172.2[0-9].*|172.3[0-1].*|192.168.*) echo "$ip"; return ;;
        esac
    done
    echo ""
}

install -d -o root -g root -m 0755 /etc/zynervox
install -d -o root -g root -m 0755 "$DATA_DIR"

# Semilla de categorias: sin este archivo el servicio crashea al arrancar
# (RuleStore::snapshot() se llama en el lifespan de FastAPI y revienta si no
# puede leer el JSON). Se usa el set ya validado contra audio real en
# docker_converxa (sesion zypad-vosk 0.5.0): sin terminos genericos que
# generen falsos positivos.
if [[ ! -f "$DATA_DIR/categories.json" ]]; then
    cat > "$DATA_DIR/categories.json" <<'JSON'
{
  "casilla": [
    "buzon",
    "buzon de voz",
    "casilla",
    "casilla de voz",
    "deje su mensaje",
    "despues del tono",
    "mensaje"
  ],
  "numero_no_existe": [
    "no existe",
    "no exite",
    "numero no existe",
    "numero no corresponde"
  ],
  "sin_servicio": [
    "fuera de servicio",
    "sin servicio",
    "temporalmente fuera de servicio"
  ],
  "ocupado": [
    "linea ocupada",
    "ocupado"
  ]
}
JSON
fi

if [[ -f "$CONFIG_ENV" ]]; then
    # shellcheck disable=SC1090
    source "$CONFIG_ENV"
else
    ZYPAD_API_KEY="$(openssl rand -hex 24)"
    ZYPAD_ADMIN_USER="admin"
    ZYPAD_ADMIN_PASSWORD="$(openssl rand -hex 12)"
    ZYPAD_BIND_IP="${ZYPAD_BIND_IP:-$(detect_bind_ip)}"
    if [[ -z "$ZYPAD_BIND_IP" ]]; then
        ZYPAD_BIND_IP="0.0.0.0"
        echo "AVISO: no se detectó IP LAN privada; Zypad publicará el puerto $PORT en 0.0.0.0 (todas las interfaces, incluida la pública si el host tiene una). Revise firewall/seguridad." >&2
    fi
    umask 077
    printf 'ZYPAD_API_KEY=%q\nZYPAD_ADMIN_USER=%q\nZYPAD_ADMIN_PASSWORD=%q\nZYPAD_BIND_IP=%q\nZYPAD_PORT=%q\n' \
        "$ZYPAD_API_KEY" "$ZYPAD_ADMIN_USER" "$ZYPAD_ADMIN_PASSWORD" "$ZYPAD_BIND_IP" "$PORT" > "$CONFIG_ENV"
fi
# shellcheck disable=SC1090
source "$CONFIG_ENV"

docker image inspect "$IMAGE" >/dev/null 2>&1 || docker pull "$IMAGE"

if ! docker inspect "$INSTANCE" >/dev/null 2>&1; then
    docker run -d --name "$INSTANCE" --restart unless-stopped \
        -p "${ZYPAD_BIND_IP}:${ZYPAD_PORT}:8767" \
        -e "VOSK_API_KEY=${ZYPAD_API_KEY}" \
        -e "ADMIN_USER=${ZYPAD_ADMIN_USER}" \
        -e "ADMIN_PASSWORD=${ZYPAD_ADMIN_PASSWORD}" \
        -e "MAX_AUDIO_BYTES=10485760" \
        -e "MAX_CONCURRENT=2" \
        -v "${DATA_DIR}:/app/data" \
        "$IMAGE" >/dev/null
fi

cat > "$CONFIG_PHP" <<EOF
<?php
return [
    'container' => '$INSTANCE',
    'bind_ip' => '$ZYPAD_BIND_IP',
    'port' => $ZYPAD_PORT,
    'api_key' => '$ZYPAD_API_KEY',
    'admin_user' => '$ZYPAD_ADMIN_USER',
    'admin_password' => '$ZYPAD_ADMIN_PASSWORD',
];
EOF
chown root:"$WEB_GROUP" "$CONFIG_PHP"
chmod 0640 "$CONFIG_PHP"

# sudoers acotado a los 4 comandos exactos que necesita el panel web -- nada
# de meter a www-data al grupo docker (eso equivale a root sobre todo el
# host). Se valida con visudo antes de instalar: una regla invalida no debe
# poder tumbar sudo en el servidor.
SUDOERS_FILE=/etc/sudoers.d/zynervox-zypad
SUDOERS_TMP="$(mktemp)"
cat > "$SUDOERS_TMP" <<EOF
$WEB_GROUP ALL=(root) NOPASSWD: /usr/bin/docker start $INSTANCE
$WEB_GROUP ALL=(root) NOPASSWD: /usr/bin/docker stop $INSTANCE
$WEB_GROUP ALL=(root) NOPASSWD: /usr/bin/docker inspect -f {{.State.Running}} $INSTANCE
$WEB_GROUP ALL=(root) NOPASSWD: /usr/bin/docker stats --no-stream $INSTANCE
EOF
if visudo -cf "$SUDOERS_TMP" >/dev/null 2>&1; then
    install -o root -g root -m 0440 "$SUDOERS_TMP" "$SUDOERS_FILE"
    rm -f "$SUDOERS_TMP"
else
    echo "AVISO: regla sudoers de Zypad inválida, no se instaló (archivo conservado en $SUDOERS_TMP para revisar)" >&2
fi

echo "ZYPAD_READY instance=$INSTANCE bind=${ZYPAD_BIND_IP}:${ZYPAD_PORT} image=$IMAGE"
