#!/bin/bash
set -euo pipefail
source=/mnt/zynervoxv2-fuente
stage=$(mktemp -d /tmp/zynervox-agent-controls-XXXXXXXX)
server_pid=''
trap 'if [[ -n "$server_pid" ]]; then kill "$server_pid" 2>/dev/null || true; fi; rm -rf -- "$stage"' EXIT
mkdir -p "$stage/includes" "$stage/config" "$stage/modules/admin" "$stage/modules/sup" "$stage/sessions"
for file in Dialer.php Database.php Audit.php Phones.php Auth.php ServerInfo.php Dialplans.php DialplanOrigins.php Carriers.php; do cp "$source/app/web/includes/$file" "$stage/includes/$file"; done
cp "$source/src/features/dialer/tests/fixtures/PbxAmi-controls.php" "$stage/includes/PbxAmi.php"
for file in dialer_agent.php sidebar.php; do cp "$source/app/web/modules/admin/$file" "$stage/modules/admin/$file"; done
cp "$source/app/web/modules/sup/sidebar.php" "$stage/modules/sup/sidebar.php"
cp "$source/app/web/logout.php" "$stage/logout.php"
cp "$source/app/web/config/Config.php" "$stage/config/Config.php"
cp /srv/www/htdocs/zynervoxv2/config/deployment.php "$stage/config/deployment.php"
DIALER_TEST_WEB_ROOT="$stage" php "$source/src/features/dialer/tests/agent-controls.php"
port=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
DIALER_TEST_WEB_ROOT="$stage" php -d session.save_path="$stage/sessions" -S "127.0.0.1:$port" "$source/src/features/dialer/tests/agent-controls-router.php" > "$stage/server.log" 2>&1 &
server_pid=$!
python3 - "$port" <<'PY'
import sys, time, json, urllib.request, urllib.error, urllib.parse
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args): return None
opener=urllib.request.build_opener(NoRedirect)
base='http://127.0.0.1:'+sys.argv[1]+'/'
def request(query,values=None):
    data=urllib.parse.urlencode(values).encode() if values is not None else None
    try: response=opener.open(urllib.request.Request(base+query,data=data),timeout=30)
    except urllib.error.HTTPError as error: response=error
    return response.code,response.read().decode()
for attempt in range(30):
    try: status,body=request('?role=1'); break
    except OSError: time.sleep(.1)
assert status==200 and 'Entrar a cola' not in body and 'Salir de cola' not in body and 'Colgar' in body
assert body.count('action="../../logout.php"')==1, 'Agent must have a single logout form'
assert 'class="sidebar"' not in body and 'aria-current="page">CRM</a>' in body
assert 'disabled aria-disabled="true"' in body and 'WhatsApp <span' in body
for target in ('campaigns.php','users.php','reports.php','filters.php','monitor.php','whatsapp.php','campaign_monitor.php'):
    assert ('href="../admin/'+target+'"') not in body, 'Agent navigation exposes '+target
assert "action:'bootstrap'" in body and 'Motivo de pausa' in body and 'Tipos de pausa' not in body
status,body=request('?role=9'); assert status==200 and 'Tipos de pausa' in body
assert body.count('action="../../logout.php"')==1, 'Admin must have a single logout form'
assert 'class="sidebar"' in body and 'class="agent-tabs"' not in body
status,body=request('?role=8'); assert status==200 and 'class="sidebar"' in body and 'href="../admin/campaigns.php"' in body
assert 'class="agent-tabs"' not in body and body.count('action="../../logout.php"')==1
status,body=request('?role=1',{'action':'bootstrap','ajax':'1','csrf_token':'invalid'}); assert status==503 and json.loads(body)['error']
status,body=request('?role=1',{'action':'bootstrap','ajax':'1','csrf_token':'controls_fixture_token'}); assert status==200 and not json.loads(body)['error']
status,body=request('?role=1',{'action':'save_pause_type','code':'TEST','label':'Unauthorized','active':'1','csrf_token':'controls_fixture_token'}); assert 'Solo el administrador' in body
status,body=request('?role=9',{'action':'save_pause_type','code':'TEST','label':'<script>test</script>','active':'1','csrf_token':'controls_fixture_token'}); assert 'Tipo de pausa guardado' in body and '&lt;script&gt;test&lt;/script&gt;' in body
status,body=request('?role=1',{'action':'hangup','call_id':'999999','csrf_token':'controls_fixture_token'}); assert 'No tienes esa llamada activa' in body
assert request('?role=1&page=logout')[0]==403
assert request('?role=1&page=logout',{'csrf_token':'invalid'})[0]==403
assert request('?role=1&page=logout',{'csrf_token':'controls_fixture_token'})[0]==302
assert request('')[0]==302
print('PASS: agent/admin controls, automatic bootstrap, CSRF, admin-only catalog, escaped labels, own-call Hangup, POST logout and anonymous rejection.')
PY
