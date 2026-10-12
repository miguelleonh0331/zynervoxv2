#!/usr/bin/env bash
set -euo pipefail
task_source="${1:?source root required}"
mysql zynervox_core < "$task_source/src/features/bot_ivr/models/013-tts-providers.sql"
mysql <<'SQL'
GRANT SELECT,INSERT ON zynervox_core.zynervox_bot_tts_providers TO 'zynervoxv2_bot_ivr'@'localhost';
GRANT SELECT,INSERT ON zynervox_core.zynervox_bot_tts_providers TO 'zynervoxv2_bot_ivr'@'127.0.0.1';
SQL
