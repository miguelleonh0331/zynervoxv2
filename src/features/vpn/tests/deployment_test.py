"""Root-only deployment check; temporary VPN account, authenticated web actions."""
import datetime
import http.cookiejar
import json
import os
from pathlib import Path
import re
import subprocess
import time
import urllib.error
import urllib.parse
import urllib.request

BASE = 'http://127.0.0.1/zynervoxv2/'
token = Path('/etc/zynervox/zynervoxv2/vpn/api-token').read_text().strip()
jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
username = 'paneltest' + datetime.datetime.now().strftime('%H%M%S')


def api(action, data=None):
    payload = json.dumps({'action': action, 'data': data or {}, 'actor': 'deployment-test', 'ip': '127.0.0.1'}).encode()
    req = urllib.request.Request('http://127.0.0.1:18081/api', payload,
                                 {'Content-Type': 'application/json', 'Authorization': 'Bearer ' + token})
    with urllib.request.urlopen(req, timeout=75) as response:
        return json.load(response)


def page(path='modules/vpn/index.php', data=None):
    with opener.open(BASE + path, urllib.parse.urlencode(data).encode() if data is not None else None, timeout=80) as response:
        return response.read().decode(), response.headers


try:
    urllib.request.urlopen(BASE + 'modules/vpn/index.php')
    raise AssertionError('Anonymous access allowed')
except urllib.error.HTTPError as error:
    assert error.code == 403
    error.close()
try:
    urllib.request.urlopen(urllib.request.Request('http://127.0.0.1:18081/api', b'{}', {'Content-Type':'application/json'}))
    raise AssertionError('Anonymous API allowed')
except urllib.error.HTTPError as error:
    assert error.code == 403
    error.close()

page('index.php', {'username':'admin', 'password':os.environ['ZYNERVOX_CORE_ADMIN_PASSWORD']})
html, headers = page('modules/vpn/index.php?new=1')
assert 'Inventario de agentes' in html and 'Auditoría' in html
assert '127.0.0.1:18081' not in html and token not in html
assert headers['Cache-Control'] == 'no-store'
csrf = re.search(r'name="csrf" value="([a-f0-9]+)"', html).group(1)
try:
    page(data={'action':'create','csrf':'invalid','username':username,'display_name':'invalid'})
    raise AssertionError('CSRF accepted')
except urllib.error.HTTPError as error:
    assert error.code == 403
    error.close()

created = False
try:
    html, _ = page(data={'action':'create','csrf':csrf,'username':username,'display_name':'Prueba del panel', 'agent_ref':'QA-LOCAL'})
    assert 'Cuenta creada.' in html, html[:150]
    created = True
    html, _ = page(data={'action':'update','csrf':csrf,'username':username,'display_name':'<script>alert(1)</script>', 'agent_ref':'QA-EDIT'})
    assert 'Inventario actualizado.' in html
    assert '&lt;script&gt;alert(1)&lt;/script&gt;' in html
    profile, headers = page(data={'action':'download','csrf':csrf,'username':username})
    assert '<key>' in profile and '<cert>' in profile
    assert username + '.ovpn' in headers['Content-Disposition']
    assert headers['Cache-Control'] == 'no-store'
    del profile
    subprocess.run(['docker','run','-d','--name','zynervoxv2-vpn-panel-check','--network','zynervoxv2-vpn_vpn',
                    '--cap-add','NET_ADMIN','--device','/dev/net/tun','-v','zynervoxv2-vpn-data:/etc/openvpn:ro',
                    '--entrypoint','openvpn','hwdsl2/openvpn-server@sha256:8f9b421bc4b510f7d2e7d43d10bafc873de953fd42f7df00938ac90cf4937243',
                    '--config','/etc/openvpn/clients/' + username + '.ovpn','--route-nopull','--verb','3'],
                   check=True, stdout=subprocess.DEVNULL)
    for _ in range(25):
        logs = subprocess.check_output(['docker','logs','zynervoxv2-vpn-panel-check'], stderr=subprocess.STDOUT).decode()
        if 'Initialization Sequence Completed' in logs:
            break
        time.sleep(1)
    assert 'Initialization Sequence Completed' in logs, 'VPN test handshake failed'
    subprocess.run(['docker','exec','zynervoxv2-vpn-panel-check','ping','-c','2','-W','2','10.8.0.1'],check=True,stdout=subprocess.DEVNULL)
    time.sleep(6)
    inventory = api('inventory', {'target':username})
    row = next(r for r in inventory['clients'] if r['username'] == username)
    assert row['last_connected'] and row['session']
    assert any(event['action'] == 'VPN_CONNECT' for event in api('audit', {'target':username})['audit'])
    html, _ = page(data={'action':'revoke','csrf':csrf,'username':username})
    assert 'Acceso revocado.' in html
    time.sleep(6)
    inventory = api('inventory', {'target':username})
    row = next(r for r in inventory['clients'] if r['username'] == username)
    assert row['state'] == 'revoked' and not row['session']
    assert row['last_disconnected']
    html, _ = page(data={'action':'delete','csrf':csrf,'username':username})
    assert 'retirada del inventario' in html
    inventory = api('inventory', {'target':username})
    assert not any(r['username'] == username for r in inventory['clients'])
    history = api('audit', {'target':username})
    operations = {r['action'] for r in history['audit']}
    assert {'CREATE','UPDATE_DIFF','DOWNLOAD','REVOKE','DELETE','VPN_CONNECT','VPN_DISCONNECT'} <= operations
    assert token not in json.dumps(history) and '<key>' not in json.dumps(history)
    cookies = [dict(name=c.name,value=c.value,domain='100.113.29.10',path=c.path,
                    expires=-1,httpOnly=True,secure=False,sameSite='Lax') for c in jar]
    path = '/root/zynervox-install/browser-vpn-state.json'
    with os.fdopen(os.open(path,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600),'w') as f:
        json.dump(dict(cookies=cookies,origins=[]),f)
    print('PASS: admin/anonymous/CSRF, create/edit/XSS/download/revoke/delete, real VPN hook/session, append-only audit.')
finally:
    subprocess.run(['docker','rm','-f','zynervoxv2-vpn-panel-check'],stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
    if created:
        inventory = api('inventory')
        if any(r['username'] == username for r in inventory['clients']):
            api('delete', {'username':username})
