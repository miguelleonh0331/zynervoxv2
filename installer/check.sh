#!/usr/bin/env bash
set -euo pipefail

WEB_ROOT="${WEB_ROOT:-/var/www/html/zynervox}"
fail=0
for command in apache2ctl php python3 mysql asterisk; do
  if ! command -v "$command" >/dev/null 2>&1; then
    echo "FALTA comando: $command"
    fail=1
  fi
done
for extension in curl json mbstring mysqli pdo_mysql session xml zip; do
  if ! php -m | grep -qi "^${extension}$"; then
    echo "FALTA extensión PHP: $extension"
    fail=1
  fi
done
for path in /etc/astguiclient.conf "$WEB_ROOT/index.php"; do
  if [[ ! -f "$path" ]]; then
    echo "FALTA archivo: $path"
    fail=1
  fi
done
python3 -c 'import pymysql, num2words' 2>/dev/null || { echo "FALTAN módulos Python"; fail=1; }
apache2ctl configtest
echo "CHECK_RESULT=$([[ $fail -eq 0 ]] && echo OK || echo FAIL)"
exit "$fail"
