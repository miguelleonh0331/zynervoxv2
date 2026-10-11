#!/bin/bash
set -euo pipefail
[[ "${DIALER_LAB_CONFIRM:-}" == SERVICE_RESTART ]] || { echo 'Explicit SERVICE_RESTART confirmation required'; exit 2; }
asterisk -rx 'core show channels count' | grep -q '^0 active channels$' || { echo 'PBX must be idle for the restart test'; exit 2; }
old_pid=$(systemctl show zynervox-dialer.service -p MainPID --value)
lock_inode=$(stat -c %i /run/zynervox-dialer/worker.lock)
systemctl kill -s SIGKILL --kill-who=main zynervox-dialer.service
for i in $(seq 1 30); do
 sleep 1
 new_pid=$(systemctl show zynervox-dialer.service -p MainPID --value)
 if [[ "$new_pid" != 0 && "$new_pid" != "$old_pid" ]] && systemctl is-active --quiet zynervox-dialer.service && lslocks -n -o PID,PATH | grep -Eq "^[[:space:]]*$new_pid[[:space:]]+/run/zynervox-dialer/worker.lock$"; then break; fi
done
[[ "$new_pid" != 0 && "$new_pid" != "$old_pid" ]]
lslocks -n -o PID,PATH | grep -Eq "^[[:space:]]*$new_pid[[:space:]]+/run/zynervox-dialer/worker.lock$"
[[ "$(stat -c %i /run/zynervox-dialer/worker.lock)" == "$lock_inode" ]]
set +e
timeout 5s runuser -u wwwrun -- php /srv/www/htdocs/zynervoxv2/modules/admin/dialer_engine_worker.php
second_status=$?
set -e
[[ "$second_status" == 1 ]]
printf 'PASS: supervisor restart old=%s new=%s; lock inode preserved; duplicate worker rejected\n' "$old_pid" "$new_pid"
systemctl show zynervox-dialer.service -p MainPID -p ActiveState -p SubState -p NRestarts
