#!/bin/sh
set -eu
umask 077
python3 /opt/vpn-admin/backend.py &
api=$!
for i in $(seq 1 30); do
  [ -S /run/zv-vpn-events.sock ] && break
  kill -0 "$api"
  sleep 1
done
test -S /run/zv-vpn-events.sock
/opt/src/run.sh &
vpn=$!
cleanup() { kill "$api" "$vpn" 2>/dev/null || true; wait || true; }
trap cleanup EXIT
trap 'exit 0' TERM INT
while kill -0 "$api" 2>/dev/null && kill -0 "$vpn" 2>/dev/null; do sleep 2; done
exit 1
