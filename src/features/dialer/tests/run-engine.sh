#!/bin/bash
set -euo pipefail
stage=$(mktemp -d /tmp/zynervox-dialer-review-XXXXXXXX)
trap 'rm -rf -- "$stage"' EXIT
mkdir -p "$stage/includes" "$stage/config"
for file in Dialer.php Database.php Audit.php Phones.php PbxAmi.php DialplanOrigins.php Dialplans.php; do
  cp "/mnt/zynervoxv2-fuente/app/web/includes/$file" "$stage/includes/$file"
done
cp /mnt/zynervoxv2-fuente/app/web/config/Config.php "$stage/config/Config.php"
cp /srv/www/htdocs/zynervoxv2/config/deployment.php "$stage/config/deployment.php"
DIALER_TEST_WEB_ROOT="$stage" php /mnt/zynervoxv2-fuente/src/features/dialer/tests/engine.php
