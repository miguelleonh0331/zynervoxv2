#!/bin/bash
# mirmidon compatibility deployment. Pass a staged repository tree; config starts disabled.
set -euo pipefail
stage=${1:?Usage: bot-ivr-baresip.sh /absolute/staged-tree}
test "$(id -u)" = 0
test "${stage:0:1}" = /
test "$(mysql --batch --skip-column-names -e "SELECT COUNT(*) FROM zynervox_core.zynervox_bot_dial_runs WHERE state IN ('starting','running','stopping')")" = 0
web=/srv/www/htdocs/zynervoxv2
models=$stage/src/features/bot_ivr/models
for file in baresip_origin.php baresip_context_agi.php list_call_worker.php list_call_service.php call_tracking_agi.php; do
    php -l "$stage/app/web/bot_ivr/$file"
done
php -l "$stage/app/web/includes/DialplanOrigins.php"
/usr/local/bin/python3.11 -c 'import ast,sys;ast.parse(open(sys.argv[1]).read())' "$stage/asterisk/synervox/modules/bot_ivr/baresip_origin.py"
visudo -cf "$models/zynervoxv2-baresip-wwwrun.sudoers"
stamp=$(date +%Y%m%d-%H%M%S)
for file in baresip_origin.php baresip_context_agi.php list_call_worker.php list_call_service.php call_tracking_agi.php; do
    if test -f "$web/bot_ivr/$file"; then cp -p "$web/bot_ivr/$file" "$web/bot_ivr/$file.bak.$stamp"; fi
    install -o root -g www -m 0640 "$stage/app/web/bot_ivr/$file" "$web/bot_ivr/$file"
done
chmod 0750 "$web/bot_ivr/call_tracking_agi.php"
cp -p "$web/includes/DialplanOrigins.php" "$web/includes/DialplanOrigins.php.bak.$stamp"
install -o root -g www -m 0640 "$stage/app/web/includes/DialplanOrigins.php" "$web/includes/DialplanOrigins.php"
install -d -o root -g root -m 0700 /var/lib/zynervoxv2/asterisk/bot_ivr/baresip_leases
install -d -o root -g www -m 0750 /etc/zynervoxv2
if ! test -f /etc/zynervoxv2/baresip-origin.json; then
    install -o root -g www -m 0640 "$models/baresip-origin.example.json" /etc/zynervoxv2/baresip-origin.json
fi
install -o root -g root -m 0755 "$stage/asterisk/synervox/modules/bot_ivr/baresip_origin.py" /usr/local/libexec/zynervoxv2-baresip-origin.py
install -o root -g root -m 0440 "$models/zynervoxv2-baresip-wwwrun.sudoers" /etc/sudoers.d/zynervoxv2-baresip-wwwrun
install -o root -g root -m 0644 "$models/zynervoxv2-baresip-sweep.service" /etc/systemd/system/zynervoxv2-baresip-sweep.service
install -o root -g root -m 0644 "$models/zynervoxv2-baresip-sweep.timer" /etc/systemd/system/zynervoxv2-baresip-sweep.timer
systemctl daemon-reload
systemctl enable --now zynervoxv2-baresip-sweep.timer
printf 'Baresip sources installed. Inbound route/dialplan reload and enabled=true remain explicit activation steps. Backup suffix: %s\n' "$stamp"
