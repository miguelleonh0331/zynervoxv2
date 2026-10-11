#!/bin/bash
set -euo pipefail
stage=$(mktemp -d /tmp/zynervox-dialer-routing-XXXXXXXX)
trap 'rm -rf -- "$stage"' EXIT
source=/mnt/zynervoxv2-fuente
mkdir -p "$stage/includes" "$stage/config" "$stage/runtime/modules/asterisk"
for file in Dialer.php Database.php Audit.php Phones.php Dialplans.php DialplanOrigins.php Carriers.php; do cp "$source/app/web/includes/$file" "$stage/includes/$file"; done
cp "$source/src/features/dialer/tests/fixtures/PbxAmi-deletion.php" "$stage/includes/PbxAmi.php"
cp "$source/app/web/config/Config.php" "$stage/config/Config.php"
php -r '$config=require $argv[1]; $config["runtime"]=$argv[2]."/runtime"; file_put_contents($argv[2]."/config/deployment.php","<?php return ".var_export($config,true).";");' /srv/www/htdocs/zynervoxv2/config/deployment.php "$stage"
DIALER_TEST_WEB_ROOT="$stage" php "$source/src/features/dialer/tests/routing.php"
