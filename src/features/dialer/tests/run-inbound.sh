#!/bin/bash
set -euo pipefail
source=/opt/zynervoxv2-fuente
stage=$(mktemp -d /tmp/zynervox-inbound-XXXXXXXX)
trap 'rm -rf -- "$stage"' EXIT
mkdir -p "$stage/includes" "$stage/config"
for file in Dialer.php Database.php Audit.php Phones.php; do cp "$source/app/web/includes/$file" "$stage/includes/$file"; done
cp "$source/src/features/dialer/tests/fixtures/PbxAmi-controls.php" "$stage/includes/PbxAmi.php"
cp "$source/app/web/config/Config.php" "$stage/config/Config.php"
cp /srv/www/htdocs/zynervoxv2/config/deployment.php "$stage/config/deployment.php"
DIALER_TEST_WEB_ROOT="$stage" php "$source/src/features/dialer/tests/inbound.php"
