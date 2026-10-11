#!/bin/bash
set -euo pipefail
stage=$(mktemp -d /tmp/zynervox-dialer-http-XXXXXXXX)
server_pid=''
audio_fixture=/var/spool/asterisk/monitor/zynervoxv2/dialer/call-900000001.wav
[[ ! -e "$audio_fixture" && ! -L "$audio_fixture" ]] || { echo 'Audio fixture already exists'; exit 2; }
cp /mnt/zynervoxv2-fuente/src/features/dialer/tests/fixtures/lab-message.wav "$audio_fixture"
trap 'if [[ -n "$server_pid" ]]; then kill "$server_pid" 2>/dev/null || true; fi; rm -f -- "$audio_fixture"; rm -rf -- "$stage"' EXIT
mkdir -p "$stage/includes" "$stage/config" "$stage/modules/admin" "$stage/modules/sup" "$stage/sessions"
for file in Dialer.php Database.php Audit.php Phones.php PbxAmi.php Auth.php ServerInfo.php; do
  cp "/mnt/zynervoxv2-fuente/app/web/includes/$file" "$stage/includes/$file"
done
for file in dialer_report.php dialer_recordings.php dialer_campaign_edit.php dialer_agent.php sidebar.php; do
  cp "/mnt/zynervoxv2-fuente/app/web/modules/admin/$file" "$stage/modules/admin/$file"
done
cp /mnt/zynervoxv2-fuente/app/web/modules/sup/sidebar.php "$stage/modules/sup/sidebar.php"
cp /mnt/zynervoxv2-fuente/app/web/config/Config.php "$stage/config/Config.php"
cp /srv/www/htdocs/zynervoxv2/config/deployment.php "$stage/config/deployment.php"
port=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
DIALER_HTTP_AUDIO_FIXTURE=1 DIALER_TEST_WEB_ROOT="$stage" php -d session.save_path="$stage/sessions" -S "127.0.0.1:$port" /mnt/zynervoxv2-fuente/src/features/dialer/tests/report-router.php > "$stage/server.log" 2>&1 &
server_pid=$!
python3 - "$port" <<'PY'
import sys, urllib.request, urllib.error, time
port = sys.argv[1]
base='http://127.0.0.1:'+port+'/'
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self, req, fp, code, msg, headers, newurl): return None
opener=urllib.request.build_opener(NoRedirect)
def get(query):
    try: r=opener.open(base+query, timeout=30)
    except urllib.error.HTTPError as e: r=e
    return r.code, dict(r.headers), r.read().decode('utf-8')
for attempt in range(30):
    try: status, headers, body=get('?role=9'); break
    except OSError: time.sleep(.1)
else: raise RuntimeError('HTTP fixture server unavailable')
assert status==200, (status,body[:300])
assert 'Abandono por nivel' in body and 'Ocupaci\u00f3n de agente' in body and 'N/D' in body
for role in [7,8]:
    assert get('?role='+str(role))[0]==200
assert get('?role=1')[0]==302
assert get('')[0]==302
assert get('?role=9&id=999')[0]==404
assert get('?role=9&id=x')[0]==400
assert get('?role=9&since=2026-02-30')[0]==400
assert get('?role=9&since=2026-10-02&until=2026-10-01')[0]==400
assert get('?role=9&since=%22%3E%3Cscript%3E')[0]==400
assert get('?role=9&page=campaign')[0]==200
assert get('?role=1&page=campaign')[0]==302
assert get('?role=1&page=agent')[0]==200
import json
status, headers, body=get('?role=1&page=agent&poll=1')
assert status==200 and 'sessions' in json.loads(body)
def post(values):
    import urllib.parse
    request=urllib.request.Request(base+'?role=9&page=campaign',data=urllib.parse.urlencode(values).encode())
    try: response=opener.open(request,timeout=30)
    except urllib.error.HTTPError as error: response=error
    return response.code,response.read().decode('utf-8')
assert post({'action':'stop_engine','csrf_token':'invalid'})[0]!=200
status, body=post({'action':'stop_engine','csrf_token':'private_http_fixture_token'})
assert status==200 and 'STOPPED' in body
print('PASS: HTTP report render, admin/supervisor/GTR, anonymous and agent rejection, missing campaign and invalid dates.')
print('PASS: campaign/agent panels, authenticated JSON polling, admin-only engine control and CSRF rejection.')
status, headers, body=get('?role=9&view=recordings')
assert status==200 and '<audio controls' in body and 'Archivo no disponible' in body
assert get('?role=9&view=recordings&phone=9999')[2].count('<audio controls')==0
assert get('?role=9&view=recordings&phone=%3Cscript%3E')[0]==400
assert get('?role=1&view=recordings')[0]==302
assert get('?view=recordings&audio=900000001')[0]==302
for query, expected, request_headers in [
 ('?role=9&view=recordings&audio=900000001',200,{}),
 ('?role=8&view=recordings&audio=900000001',206,{'Range':'bytes=0-43'}),
 ('?role=7&view=recordings&audio=900000001',206,{'Range':'bytes=-20'}),
 ('?role=9&view=recordings&audio=900000001',416,{'Range':'bytes=999999-'}),
 ('?role=9&view=recordings&audio=900000001',416,{'Range':'bytes=0-3,5-8'}),
 ('?role=9&view=recordings&audio=900000002',404,{}),
 ('?role=9&view=recordings&audio=x',400,{})]:
    try: response=opener.open(urllib.request.Request(base+query,headers=request_headers),timeout=30)
    except urllib.error.HTTPError as error: response=error
    payload=response.read()
    assert response.code==expected, (query,response.code,payload[:100])
    if expected in [200,206]:
        assert response.headers['Content-Type']=='audio/wav' and 'no-store' in response.headers['Cache-Control']
        assert len(payload)==int(response.headers['Content-Length'])
        if request_headers.get('Range')=='bytes=0-43': assert len(payload)==44 and payload[:4]==b'RIFF'
print('PASS: authenticated audio search, full WAV, byte ranges, suffix, invalid ranges, path guard and roles.')
PY
