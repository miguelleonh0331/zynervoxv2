#!/bin/bash
set -euo pipefail
source_root=/mnt/zynervoxv2-fuente
mountpoint -q "$source_root"
getent passwd wwwrun >/dev/null
php -l /srv/www/htdocs/zynervoxv2/modules/admin/dialer_engine_worker.php
backup=/var/backups/zynervoxv2/dialer-service-$(date -u +%Y%m%d-%H%M%S)
install -d -m 0700 "$backup"
if [[ -f /etc/systemd/system/zynervox-dialer.service ]]; then cp -a /etc/systemd/system/zynervox-dialer.service "$backup/"; fi
install -o root -g root -m 0644 "$source_root/src/features/dialer/services/zynervox-dialer.service" /etc/systemd/system/zynervox-dialer.service
systemd-analyze verify /etc/systemd/system/zynervox-dialer.service
systemctl daemon-reload
systemctl enable --now zynervox-dialer.service
systemctl is-active zynervox-dialer.service
echo "Installed worker; campaigns keep their configured RUNNING/STOPPED state."
