#!/usr/bin/env bash
set -euo pipefail

WEB_ROOT="${WEB_ROOT:-/var/www/html/zynervox}"
STRICT=0
[[ "${1:-}" == "--strict" ]] && STRICT=1
fail=0
warn=0

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
