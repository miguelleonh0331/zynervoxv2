import datetime as dt
import importlib.util
from pathlib import Path
import sys
import threading
from http.server import HTTPServer, BaseHTTPRequestHandler
spec = importlib.util.spec_from_file_location('ivr_io', sys.argv[1])
io = importlib.util.module_from_spec(spec)
spec.loader.exec_module(io)
today = dt.date(2026, 10, 10)
for phrase, expected in [('mañana', '2026-10-11'), ('pasado mañana', '2026-10-12'), ('el siguiente sábado', '2026-10-17'), ('el martes', '2026-10-13'), ('11/10/2026', '2026-10-11')]:
    result = io.capture_value(phrase, 'date', {}, today)
    assert result['ok'] and result['value'] == expected, (phrase, result)
assert not io.capture_value('no sé', 'date', {}, today)['ok']
assert not io.capture_value('[silencio]', 'text', {}, today)['ok']
assert not io.capture_value('31/02/2026', 'date', {}, today)['ok']
assert io.capture_value('mi número es 125', 'number', {}, today)['value'] == '125'
assert io.spoken_date(today) == 'sábado 10 de octubre'
try:
    io.dispatch('execute', {'url': 'http://127.0.0.1/private'}, {'execute_allowlist': []}, Path('/runtime'))
    raise AssertionError('unallowed service accepted')
except ValueError:
    pass
class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        self.send_response(302 if self.path.startswith('/redirect') else 200)
        if self.path.startswith('/redirect'):
            self.send_header('Location', '/target')
        self.end_headers()
        self.wfile.write(b'ok')
    def log_message(self, *args):
        pass
server = HTTPServer(('127.0.0.1', 0), Handler)
thread = threading.Thread(target=server.serve_forever, daemon=True)
thread.start()
try:
    base = 'http://127.0.0.1:' + str(server.server_port)
    config = {'execute_allowlist': [base + '/target', base + '/redirect']}
    assert io.dispatch('execute', {'url': base + '/target?value=test', 'timeout_ms': 500}, config, Path('/runtime'))['ok']
    assert not io.dispatch('execute', {'url': base + '/redirect', 'timeout_ms': 500}, config, Path('/runtime'))['ok']
finally:
    server.shutdown()
    server.server_close()
print('PASS: Spanish relative/calendar dates, capture modes, silence and service restrictions')
print('PASS: real HTTP GET adapter and redirect rejection on isolated fixture service')
