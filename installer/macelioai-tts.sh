#!/usr/bin/env bash
# Instala el motor de audio local "Marcelo IA" del IVR Builder: un venv
# Python dedicado con gTTS, usado por generate_audio.php vía
# services/tts/generate_macelioai_wav.py (gTTS -> ffmpeg -> sox).
#
# No es opcional/flaggeado: el IVR Builder siempre se despliega como parte
# de app/web, asi que este venv debe existir siempre que exista WEB_ROOT.
# Se ejecuta vía run_module en install.sh: su fallo no aborta el resto de
# la instalacion, solo queda reportado en el resumen final.
set -euo pipefail

WEB_ROOT="${WEB_ROOT:-/var/www/html/zynervox}"
WEB_GROUP="${WEB_GROUP:-www-data}"
VENV_DIR="$WEB_ROOT/venvs/gtts_env"

command -v python3 >/dev/null 2>&1 || { echo "FALTA python3 (requerido por macelioai/gTTS)" >&2; exit 1; }
command -v ffmpeg  >/dev/null 2>&1 || { echo "FALTA ffmpeg (requerido por macelioai/gTTS)" >&2; exit 1; }
command -v sox     >/dev/null 2>&1 || { echo "FALTA sox (requerido por macelioai/gTTS)" >&2; exit 1; }

if [[ ! -d "$VENV_DIR" ]]; then
  python3 -m venv "$VENV_DIR"
fi

# El WEB_INSTALLED de install.sh aplica un chmod recursivo a todo WEB_ROOT
# (incluye venvs/, que vive adentro) y lo deja todo en 0640 sin +x. Si el
# venv ya existia de una instalacion previa, sus binarios (pip, activate,
# etc.) quedan sin ejecutar. Por eso los permisos se normalizan ANTES de
# usar pip, no solo al final: el script debe ser idempotente sin importar
# en que estado haya quedado la carpeta.
chown -R root:"$WEB_GROUP" "$VENV_DIR"
find "$VENV_DIR" -type d -exec chmod 0750 {} +
find "$VENV_DIR" -type f -exec chmod 0640 {} +
find "$VENV_DIR/bin" -type f -exec chmod 0750 {} +

"$VENV_DIR/bin/pip" install --upgrade pip -q
"$VENV_DIR/bin/pip" install gTTS -q

"$VENV_DIR/bin/python" -c "import gtts" || {
  echo "FALLO: gTTS no quedo importable en $VENV_DIR" >&2
  exit 1
}

echo "MACELIOAI_TTS_INSTALLED venv=$VENV_DIR"
