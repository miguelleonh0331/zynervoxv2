#!/usr/bin/env bash
set -euo pipefail

WEB_ROOT="${WEB_ROOT:-/var/www/html/zynervox}"
STRICT=0
[[ "${1:-}" == "--strict" ]] && STRICT=1
fail=0
warn=0
CHECK_FARM="${CHECK_FARM:-0}"
CHECK_STT_PROVIDERS="${CHECK_STT_PROVIDERS:-0}"
CHECK_ZYNERDESK="${CHECK_ZYNERDESK:-0}"
CHECK_WHATSAPP="${CHECK_WHATSAPP:-0}"
CHECK_ZYPAD="${CHECK_ZYPAD:-0}"

for command in php python3; do
  if ! command -v "$command" >/dev/null 2>&1; then
    echo "FALTA comando: $command"
    fail=1
  fi
done
for command in apache2ctl mysql asterisk; do
  if ! command -v "$command" >/dev/null 2>&1; then
    echo "AVISO comando opcional: $command"
    warn=1
  fi
done
for extension in curl json mbstring mysqli pdo_mysql session xml zip; do
  if ! php -m | grep -qi "^${extension}$"; then
    echo "FALTA extensión PHP: $extension"
    fail=1
  fi
done

[[ -f "$WEB_ROOT/index.php" ]] || { echo "FALTA archivo: $WEB_ROOT/index.php"; fail=1; }
[[ -f "$WEB_ROOT/modules/admin/farm.php" ]] || { echo "FALTA módulo Farm"; fail=1; }
[[ -f "$WEB_ROOT/modules/admin/stt_providers.php" ]] || { echo "FALTA módulo Stt Providers"; fail=1; }
if [[ "$CHECK_FARM" == 1 ]]; then
  for service in "${FARM_INSTANCE:-zynervox-farm}-annex.service" "${FARM_INSTANCE:-zynervox-farm}-control.service"; do
    systemctl is-active --quiet "$service" || { echo "FALTA servicio activo: $service"; fail=1; }
  done
  [[ -f "$WEB_ROOT/modules/admin/farm_app/config.local.php" ]] || { echo "FALTA configuración Farm"; fail=1; }
fi
if [[ "$CHECK_STT_PROVIDERS" == 1 ]]; then
  [[ -f "$WEB_ROOT/modules/admin/stt_providers_app/config/db.php" ]] || { echo "FALTA configuración Stt Providers"; fail=1; }
fi
if [[ "$CHECK_WHATSAPP" == 1 ]]; then
  [[ -f "$WEB_ROOT/modules/admin/whatsapp.php" ]] || { echo "FALTA módulo WhatsApp"; fail=1; }
  whatsapp_service="${WHATSAPP_INSTANCE:-zynervox-whatsapp}.service"
  systemctl is-active --quiet "$whatsapp_service" || { echo "FALTA servicio activo: $whatsapp_service"; fail=1; }
  [[ -f "/etc/zynervox/${WHATSAPP_INSTANCE:-zynervox-whatsapp}.env" ]] || { echo "FALTA configuración WhatsApp"; fail=1; }
  [[ -f /etc/zynervox/whatsapp.conf ]] || { echo "FALTA configuración de proxy WhatsApp (install-proxy)"; fail=1; }
fi
if [[ "$CHECK_ZYNERDESK" == 1 ]]; then
  [[ -f "$WEB_ROOT/modules/admin/zynerdesk.php" ]] || { echo "FALTA módulo Zynerdesk"; fail=1; }
  zynerdesk_service="${ZYNERDESK_INSTANCE:-zynervox-zynerdesk}.service"
  systemctl is-active --quiet "$zynerdesk_service" || { echo "FALTA servicio activo: $zynerdesk_service"; fail=1; }
  if [[ -f /etc/zynervox/zynerdesk.conf ]]; then
    # La vista integrada arma la pagina pidiendosela al servicio nativo por
    # loopback, asi que necesita el puerto publicado y la extension curl.
    zynerdesk_port="$(sed -n 's/^ZYNERDESK_PORT=//p' /etc/zynervox/zynerdesk.conf)"
    if [[ -z "$zynerdesk_port" ]]; then
      echo "FALTA ZYNERDESK_PORT en /etc/zynervox/zynerdesk.conf; reejecute zynerdesk.sh install-proxy"
      fail=1
    elif command -v curl >/dev/null 2>&1 && ! curl -fsS --max-time 5 "http://127.0.0.1:${zynerdesk_port}/login.html" >/dev/null 2>&1; then
      echo "AVISO: Zynerdesk no respondió en 127.0.0.1:${zynerdesk_port}"
      warn=1
    fi
  else
    echo "FALTA configuración Zynerdesk"
    fail=1
  fi
fi
if [[ "$CHECK_ZYPAD" == 1 ]]; then
  [[ -f /etc/zynervox/zynervox-zypad.php ]] || { echo "FALTA configuración Zypad"; fail=1; }
  if command -v docker >/dev/null 2>&1; then
    [[ "$(docker inspect -f '{{.State.Running}}' zynervox-zypad 2>/dev/null)" == "true" ]] || { echo "FALTA contenedor activo: zynervox-zypad"; fail=1; }
  else
    echo "AVISO: docker no disponible para verificar zynervox-zypad"
    warn=1
  fi
fi
if [[ ! -f /etc/astguiclient.conf && ! -f /etc/zynervox/astguiclient.conf ]]; then
  echo "AVISO: falta configuración de base"
  warn=1
fi
python3 -c 'import pymysql, num2words' 2>/dev/null || { echo "AVISO: faltan módulos Python opcionales"; warn=1; }
if command -v apache2ctl >/dev/null 2>&1 && ! apache2ctl configtest; then
  echo "AVISO: configuración Apache inválida"
  warn=1
fi

if [[ $STRICT -eq 1 && $warn -ne 0 ]]; then fail=1; fi
if [[ $fail -ne 0 ]]; then result=FAIL; elif [[ $warn -ne 0 ]]; then result=PARTIAL; else result=OK; fi
echo "CHECK_RESULT=$result"
exit "$fail"
