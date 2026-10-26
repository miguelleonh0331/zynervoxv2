#!/bin/bash
set -euo pipefail
# Raiz del arbol que contiene este script: funciona en la fuente y en worktrees.
source=$(cd "$(dirname "${BASH_SOURCE[0]}")/../../../.." && pwd)
stage=$(mktemp -d /tmp/zynervox-core-users-XXXXXXXX)
server_pid=''
trap 'if [[ -n "$server_pid" ]]; then kill "$server_pid" 2>/dev/null || true; fi; rm -rf -- "$stage"' EXIT
mkdir -p "$stage/includes" "$stage/config" "$stage/modules/admin" "$stage/sessions"
for file in Auth.php CoreUsers.php Database.php Audit.php ServerInfo.php IsolatedGate.php; do cp "$source/app/web/includes/$file" "$stage/includes/$file"; php -l "$stage/includes/$file"; done
for file in users.php users_isolated.php sidebar.php; do cp "$source/app/web/modules/admin/$file" "$stage/modules/admin/$file"; php -l "$stage/modules/admin/$file"; done
cp "$source/app/web/index.php" "$stage/index.php"
cp "$source/app/web/config/Config.php" "$stage/config/Config.php"
cp /srv/www/htdocs/zynervoxv2/config/deployment.php "$stage/config/deployment.php"
CORE_USERS_TEST_WEB_ROOT="$stage" php -d session.save_path="$stage/sessions" "$source/src/features/core/tests/users.php"
port=$(python3 -c 'import socket; s=socket.socket(); s.bind(("127.0.0.1",0)); print(s.getsockname()[1]); s.close()')
CORE_USERS_TEST_WEB_ROOT="$stage" php -d session.save_path="$stage/sessions" -S "127.0.0.1:$port" "$source/src/features/core/tests/users-router.php" > "$stage/server.log" 2>&1 &
server_pid=$!
python3 - "$port" <<'PY'
import sys,time,urllib.request,urllib.error,urllib.parse
class NoRedirect(urllib.request.HTTPRedirectHandler):
    def redirect_request(self,*args): return None
opener=urllib.request.build_opener(NoRedirect)
base='http://127.0.0.1:'+sys.argv[1]
def request(query,values=None):
    data=None if values is None else urllib.parse.urlencode(values).encode()
    try: response=opener.open(urllib.request.Request(base+'/?'+query,data=data),timeout=20)
    except urllib.error.HTTPError as error: response=error
    return response.code,response.headers,response.read().decode()
for _ in range(30):
    try: code,_,body=request('role=9');break
    except OSError: time.sleep(.1)
assert code==200 and 'Crear cuenta' in body
for role in [1,7,8]: assert request('role='+str(role))[0]==302
assert request('')[0]==302
assert request('role=9&inactive=1')[0]==302
assert request('role=9&direct=1')[0]==403
values={'user':'90001','full_name':'HTTP Agent','pass':'test-password-only','active':'Y','user_level':'9','existing_user':''}
assert request('role=9',values)[0]==403
values['csrf_token']='users-fixture-token'
code,_,body=request('role=9',values)
assert code==200 and 'Nivel inv' in body and 'Agente creado.' not in body
values['user_level']='1'
code,_,body=request('role=9',values)
assert code==200 and 'Agente creado.' in body and 'test-password-only' not in body
values.update({'user':'90002','user_level':'8'})
code,_,body=request('role=9',values)
assert code==200 and 'empezar por letra' in body
values.update({'user':'rvaldez','full_name':'HTTP Supervisor'})
code,_,body=request('role=9',values)
assert code==200 and 'Cuenta creada.' in body and 'test-password-only' not in body
code,_,body=request('role=9&edit=1')
assert code==200 and '&lt;script&gt;name&lt;/script&gt;' in body and '<script>name</script>' not in body and 'test-password-only' not in body
code,headers,_=request('login=1',{'username':'90001','password':'test-password-only'})
assert code==302 and headers['Location']=='modules/admin/dialer_agent.php'
code,headers,_=request('login=1',{'username':'rvaldez','password':'test-password-only'})
assert code==302 and headers['Location']=='modules/admin/campaign_monitor.php'
assert request('login=1',{'username':'90001','password':'wrong-password'})[0]==200
print('PASS: HTTP admin-only, role refresh, CSRF, direct-view gate, inactive session, escaped names, secret-free forms, level-9 refused over HTTP, per-level login rules and agent/supervisor login redirects.')
PY
