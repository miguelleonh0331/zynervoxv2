#!/bin/bash
set -euo pipefail
mountpoint -q /mnt/zynervoxv2-fuente
evidence=/var/backups/zynervoxv2/dialer-ten-agents-$(date -u +%Y%m%d-%H%M%S)
install -d -m 0700 "$evidence"
DIALER_LAB_CONFIRM=TEN_LOOPBACK_AGENTS DIALER_LAB_EVIDENCE="$evidence" php /mnt/zynervoxv2-fuente/src/features/dialer/tests/multi-agent-lab.php
python3 /mnt/zynervoxv2-fuente/src/features/dialer/tests/verify-lab-audio.py "$evidence"
script=$(mktemp /tmp/dialer-apache-test-XXXXXXXX.php)
trap 'rm -f "$script"' EXIT
install -o wwwrun -g www -m 0600 /mnt/zynervoxv2-fuente/src/features/dialer/tests/apache-web.php "$script"
DIALER_LAB_CONFIRM=APACHE_DIALER_TESTS runuser -u wwwrun -- php "$script"
printf 'PASS: laboratory and Apache playback; evidence=%s\n' "$evidence"
