#!/bin/bash
# Existing mirmidon isolated installation; stage contains repository-relative files.
set -euo pipefail
stage=${1:?Usage: campaign-monitor.sh /absolute/staged-tree}
test "$(id -u)" = 0
web=/srv/www/htdocs/zynervoxv2
cache=/var/lib/zynervoxv2/asterisk/campaign_monitor
stamp=$(date +%Y%m%d-%H%M%S)
files=(modules/admin/campaign_monitor_cache.php modules/admin/campaign_monitor_worker.php modules/admin/campaign_monitor_api.php bot_ivr/campaign_monitor_view.php)
for file in "${files[@]}"; do php -l "$stage/app/web/$file"; done
install -d -o wwwrun -g www -m 0750 "$cache"
for file in modules/admin/campaign_monitor_cache.php modules/admin/campaign_monitor_worker.php; do
    if test -f "$web/$file"; then cp -p "$web/$file" "$web/$file.bak.$stamp"; fi
    install -o root -g www -m 0644 "$stage/app/web/$file" "$web/$file"
done
unit=/etc/systemd/system/zynervoxv2-campaign-monitor.service
if test -f "$unit"; then cp -p "$unit" "$unit.bak.$stamp"; fi
install -o root -g root -m 0644 "$stage/src/features/admin/models/zynervoxv2-campaign-monitor.service" "$unit"
systemctl daemon-reload
systemctl enable zynervoxv2-campaign-monitor.service
systemctl restart zynervoxv2-campaign-monitor.service
ready=0
for attempt in {1..30}; do
    if test -f "$cache/snapshot.json" && test "$(stat -c %Y "$cache/snapshot.json")" -ge "$(date -d '15 seconds ago' +%s)"; then ready=1; break; fi
    sleep 1
done
test "$ready" = 1
for file in modules/admin/campaign_monitor_api.php bot_ivr/campaign_monitor_view.php; do
    cp -p "$web/$file" "$web/$file.bak.$stamp"
    install -o root -g www -m 0644 "$stage/app/web/$file" "$web/$file"
done
systemctl is-active zynervoxv2-campaign-monitor.service
printf 'Shared monitor deployed. Backup suffix: %s\n' "$stamp"
