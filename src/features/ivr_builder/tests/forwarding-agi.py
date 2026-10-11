import base64
import json
import subprocess
import sys
from pathlib import Path

helper = Path(sys.argv[1]) / 'bot_ivr' / 'forward_headers_agi.php'

def run(headers, acknowledgement='200 result=1'):
    payload = base64.b64encode(json.dumps(headers).encode()).decode()
    reply = f'agi_channel: PJSIP/fixture\n\n200 result=1 ({payload})\n{acknowledgement}\n200 result=1\n'
    return subprocess.run(['php', str(helper)], input=reply, capture_output=True, text=True, timeout=10)

good = run([{'name': 'X-Cliente', 'value': 'Prueba'}])
assert good.returncode == 0, good.stderr
assert good.stdout.splitlines() == ['GET VARIABLE ZV2_FORWARD_HEADERS', 'SET VARIABLE "PJSIP_HEADER(add,X-Cliente)" "Prueba"']
empty = run([])
assert empty.returncode == 0 and empty.stdout.splitlines() == ['GET VARIABLE ZV2_FORWARD_HEADERS']
for headers in ([{'name': 'Authorization', 'value': 'bad'}], [{'name': 'X-Cliente', 'value': 'bad\r\nInjected: yes'}]):
    bad = run(headers)
    assert bad.returncode == 1 and 'SET VARIABLE ' not in bad.stdout and 'EXEC Hangup' in bad.stdout
failed = run([{'name': 'X-Cliente', 'value': 'Prueba'}], '200 result=0')
assert failed.returncode == 1 and 'EXEC Hangup' in failed.stdout
print('PASS: outgoing header AGI, empty headers, injection rejection and write failure abort')
