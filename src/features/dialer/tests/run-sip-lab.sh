#!/bin/bash
set -euo pipefail
mountpoint -q /mnt/zynervoxv2-fuente
[[ ! -e /var/lib/asterisk/sounds/dialer-lab/message.wav ]] || { echo 'Laboratory audio already exists; refusing to overwrite'; exit 2; }
evidence=/var/backups/zynervoxv2/dialer-sip-lab-$(date -u +%Y%m%d-%H%M%S)
mkdir -p "$evidence"
install -d -m 0755 /var/lib/asterisk/sounds/dialer-lab
install -m 0644 /mnt/zynervoxv2-fuente/src/features/dialer/tests/fixtures/lab-message.wav /var/lib/asterisk/sounds/dialer-lab/message.wav
trap 'rm -f /var/lib/asterisk/sounds/dialer-lab/message.wav; rmdir /var/lib/asterisk/sounds/dialer-lab 2>/dev/null || true' EXIT
DIALER_LAB_USE_SERVICE=1 DIALER_LAB_CONFIRM=LOOPBACK_SIP DIALER_LAB_EVIDENCE="$evidence" php /mnt/zynervoxv2-fuente/src/features/dialer/tests/sip-lab.php
