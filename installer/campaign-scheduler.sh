#!/bin/bash
set -euo pipefail
stage=${1:?Usage: campaign-scheduler.sh /absolute/source-tree}
test "$(id -u)" = 0
web=/srv/www/htdocs/zynervoxv2
stamp=$(date +%Y%m%dT%H%M%S%N)
files=(campaign_schedule.php list_call_service.php list_call_worker.php campaign_scheduler.php)
for file in "${files[@]}"; do php -l "$stage/app/web/bot_ivr/$file"; done
for file in "${files[@]}"; do
    target="$web/bot_ivr/$file"
    if test -f "$target"; then cp -p "$target" "$target.bak.$stamp"; fi
    install -o root -g www -m 0640 "$stage/app/web/bot_ivr/$file" "$target"
    php -l "$target"
    cmp "$stage/app/web/bot_ivr/$file" "$target"
    md5sum "$stage/app/web/bot_ivr/$file" "$target"
done
for ext in service timer; do
    unit="zynervoxv2-campaign-scheduler.$ext"
    if test -f "/etc/systemd/system/$unit"; then cp -p "/etc/systemd/system/$unit" "/etc/systemd/system/$unit.bak.$stamp"; fi
    install -o root -g root -m 0644 "$stage/src/features/bot_ivr/models/$unit" "/etc/systemd/system/$unit"
done
systemctl daemon-reload
systemctl enable --now zynervoxv2-campaign-scheduler.timer
systemctl is-active zynervoxv2-campaign-scheduler.timer
