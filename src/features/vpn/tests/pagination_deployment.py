"""Read-only deployment checks; records only normal panel/audit access events."""
import html
import http.cookiejar
import json
import os
from pathlib import Path
import re
import urllib.parse
import urllib.request

base = 'http://127.0.0.1/zynervoxv2/'
jar = http.cookiejar.CookieJar()
opener = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
body = urllib.parse.urlencode({'username':'admin','password':os.environ['ZYNERVOX_CORE_ADMIN_PASSWORD']}).encode()
with opener.open(base + 'index.php', body) as response:
    assert response.url.endswith('/modules/admin/index.php')


def page(query=''):
    with opener.open(base + 'modules/vpn/index.php' + query) as response:
        return response.read().decode()


panel = page()
assert 'class="vpn-error"' not in panel, 'Inventory failed to load'
assert 'Ver auditoría' in panel and 'id="vpn-audit"' not in panel
first = page('?view=audit')
assert 'class="vpn-error"' not in first, re.findall(r'class="vpn-error"[^>]*>(.*?)</p>', first, re.S)
table = re.search(r'id="vpn-audit".*?<tbody>(.*?)</tbody>', first, re.S).group(1)
assert 1 <= table.count('<tr>') <= 20
older = re.search(r'href="([^"]+)"[^>]*>Más antiguos</a>', first)
assert older, 'Audit navigation: ' + first[first.find('<nav class="vpn-actions"'):][-1000:]
second = page(html.unescape(older.group(1)).split('#')[0])
table2 = re.search(r'id="vpn-audit".*?<tbody>(.*?)</tbody>', second, re.S).group(1)
assert table2.count('<tr>') <= 20 and 'Más recientes</a>' in second
assert table != table2
filtered = page('?view=audit&target=never-used-account')
assert 'Sin eventos para estos filtros.' in filtered
cookies = [dict(name=c.name,value=c.value,domain='100.113.29.10',path=c.path,
                expires=-1,httpOnly=True,secure=False,sameSite='Lax') for c in jar]
path = '/root/zynervox-install/browser-vpn-state.json'
with os.fdopen(os.open(path,os.O_WRONLY|os.O_CREAT|os.O_TRUNC,0o600),'w') as f:
    json.dump(dict(cookies=cookies,origins=[]),f)
print('PASS: panel without history; audit max20; older/newer links; filter empty result.')
print('HTML bytes: panel=' + str(len(panel.encode())) + ', audit=' + str(len(first.encode())))
