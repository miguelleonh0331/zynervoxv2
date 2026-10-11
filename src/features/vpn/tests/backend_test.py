import importlib.util
from contextlib import contextmanager
import json
import os
from pathlib import Path
import re
import sqlite3
import sys
import tempfile
import threading
import types
import unittest
import urllib.error
import urllib.request
from unittest.mock import patch

if os.name != 'posix':
    sys.modules['fcntl'] = types.SimpleNamespace(LOCK_EX=2, flock=lambda *args: None)
ROOT = Path(__file__).resolve().parents[4]
spec = importlib.util.spec_from_file_location('vpn_backend', ROOT / 'ops/vpn/admin/backend.py')
b = importlib.util.module_from_spec(spec)
spec.loader.exec_module(b)


class BackendTest(unittest.TestCase):
    def setUp(self):
        self.tmp = tempfile.TemporaryDirectory()
        root = Path(self.tmp.name)
        b.DATA = root / 'admin'
        b.PKI = root / 'pki'
        b.PKI.mkdir()
        b.CLIENTS = root / 'clients'
        b.CLIENTS.mkdir()
        b.STATUS = root / 'status'
        b.TOKEN = root / 'token'
        b.TOKEN.write_text('f' * 64)
        (b.PKI / 'index.txt').write_text('V\t361001000000Z\t\t01\tunknown\t/CN=server\nV\t361001000000Z\t\t02\tunknown\t/CN=prueba01\n')
        b.initialize()
        b.reconcile()

    def tearDown(self):
        self.tmp.cleanup()

    def test_validation_and_import(self):
        for value in ['../bad', '--help', 'server', 'ca', 'a\nb', 'ab', 'a;id', 'a' * 41]:
            with self.assertRaises(ValueError):
                b.name(value)
        with self.assertRaises(ValueError):
            b.fields({'display_name': 'A', 'email': 'bad'})
        result = b.operate('inventory', {}, 'admin', '127.0.0.1')
        self.assertEqual([r['username'] for r in result['clients']], ['prueba01'])
        self.assertIsNone(result['clients'][0]['last_connected'])
        self.assertNotIn('server', json.dumps(result['clients']))

    def test_inventory_does_not_read_history(self):
        statements = []
        original = b.db
        @contextmanager
        def traced():
            with original() as c:
                c.set_trace_callback(statements.append)
                yield c
        with patch.object(b, 'db', traced):
            result = b.operate('inventory', {}, 'admin', '127.0.0.1')
        self.assertNotIn('audit', result)
        self.assertFalse(any(re.search(r'SELECT.*\bFROM audit\b', sql, re.I) for sql in statements))

    def test_history_pages_filters_and_snapshot(self):
        for i in range(55):
            b.audit('test-agent', '127.0.0.1', 'VPN_CONNECT', 'test-agent')
        filters = {'actor':'test-agent', 'target':'test-agent', 'action':'VPN_CONNECT'}
        first = b.operate('audit', filters, 'admin', '127.0.0.1')
        self.assertEqual(len(first['audit']), 20)
        self.assertIsNone(first['after_id'])
        b.audit('test-agent', '127.0.0.1', 'VPN_CONNECT', 'test-agent')
        second = b.operate('audit', dict(filters, snapshot_id=first['snapshot_id'], before_id=first['before_id']), 'admin', '127.0.0.1')
        third = b.operate('audit', dict(filters, snapshot_id=first['snapshot_id'], before_id=second['before_id']), 'admin', '127.0.0.1')
        self.assertEqual((len(second['audit']), len(third['audit'])), (20, 15))
        self.assertIsNone(third['before_id'])
        self.assertEqual(len({row['id'] for page in (first,second,third) for row in page['audit']}), 55)
        previous = b.operate('audit', dict(filters, snapshot_id=first['snapshot_id'], after_id=second['after_id']), 'admin', '127.0.0.1')
        self.assertEqual([r['id'] for r in previous['audit']], [r['id'] for r in first['audit']])
        empty = b.operate('audit', {'target':'does-not-exist'}, 'admin','127.0.0.1')
        self.assertEqual(empty['audit'], [])
        for value in [-1, 0, True, '1', 9223372036854775808]:
            with self.assertRaises(ValueError):
                b.operate('audit', {'before_id':value}, 'admin','127.0.0.1')
        with self.assertRaises(ValueError):
            b.operate('audit', {'before_id':1,'after_id':2}, 'admin','127.0.0.1')

    def test_lifecycle_and_audit(self):
        def run(*args):
            index = b.PKI / 'index.txt'
            text = index.read_text()
            if args[0] == '--addclient':
                index.write_text(text + 'V\t361001000000Z\t\t03\tunknown\t/CN=' + args[1] + '\n')
                (b.CLIENTS / (args[1] + '.ovpn')).write_text('<key>PRIVATE_SECRET</key>')
            else:
                index.write_text(text.replace('V\t361001000000Z\t\t03', 'R\t361001000000Z\t261001000000Z\t03'))
        with patch.object(b, 'command', side_effect=run), patch.object(b, 'disconnect') as kill:
            data = {'username': 'agente01', 'display_name': 'Agente', 'email': 'a@example.com'}
            b.operate('create', data, 'admin', '127.0.0.1')
            result = b.operate('download', {'username': 'agente01'}, 'admin', '127.0.0.1')
            self.assertIn('PRIVATE_SECRET', result['profile'])
            b.operate('update', dict(data, display_name='<script>'), 'admin', '127.0.0.1')
            b.operate('delete', {'username': 'agente01'}, 'admin', '127.0.0.1')
            kill.assert_called_once_with('agente01')
            with self.assertRaises(ValueError):
                b.operate('download', {'username': 'agente01'}, 'admin', '127.0.0.1')
            with self.assertRaises(ValueError):
                b.operate('create', data, 'admin', '127.0.0.1')
        with b.db() as c:
            self.assertIsNotNone(c.execute('SELECT deleted_at FROM clients WHERE username="agente01"').fetchone()[0])
            events = json.dumps([dict(row) for row in c.execute('SELECT * FROM audit')])
            self.assertNotIn('PRIVATE_SECRET', events)
            self.assertIn('UPDATE_DIFF', events)
            with self.assertRaises(sqlite3.IntegrityError):
                c.execute('DELETE FROM audit')
            with self.assertRaises(sqlite3.IntegrityError):
                c.execute('UPDATE audit SET actor="other"')

    def test_revoke_failure_keeps_account(self):
        with patch.object(b, 'command', side_effect=RuntimeError('failed')):
            with self.assertRaises(RuntimeError):
                b.operate('delete', {'username': 'prueba01'}, 'admin', '127.0.0.1')
        with b.db() as c:
            row = c.execute('SELECT * FROM clients WHERE username="prueba01"').fetchone()
            self.assertEqual(row['state'], 'active')
            self.assertIsNone(row['deleted_at'])

    def test_management_waits_for_confirmation(self):
        from unittest.mock import MagicMock
        connection = MagicMock()
        connection.recv.side_effect = [b'>INFO:ready\r\n', b'SUCCESS: common ', b'name killed\r\n']
        context = MagicMock()
        context.__enter__.return_value = connection
        with patch.object(b.socket, 'create_connection', return_value=context):
            b.disconnect('prueba01')
        self.assertEqual(connection.sendall.call_args_list[0].args[0], b'kill prueba01\n')
        self.assertEqual(connection.sendall.call_args_list[1].args[0], b'quit\n')

    def test_duplicate_create_is_serialized(self):
        successes, failures = [], []
        def run(*args):
            time.sleep(0.02)
            with (b.PKI / 'index.txt').open('a') as index:
                index.write('V\t361001000000Z\t\t03\tunknown\t/CN=' + args[1] + '\n')
        def create():
            try:
                b.operate('create', {'username':'parallel01','display_name':'Agent'}, 'admin','127.0.0.1')
                successes.append(True)
            except ValueError:
                failures.append(True)
        import time
        with patch.object(b, 'command', side_effect=run) as command:
            threads = [threading.Thread(target=create) for _ in range(2)]
            for thread in threads: thread.start()
            for thread in threads: thread.join()
            self.assertEqual(command.call_count, 1)
        self.assertEqual((len(successes), len(failures)), (1, 1))

    def test_api_authorization(self):
        server = b.ThreadingHTTPServer(('127.0.0.1', 0), b.Handler)
        thread = threading.Thread(target=server.serve_forever, daemon=True)
        thread.start()
        try:
            url = 'http://127.0.0.1:' + str(server.server_port) + '/api'
            req = urllib.request.Request(url, b'{}', {'Content-Type': 'application/json'})
            with self.assertRaises(urllib.error.HTTPError) as result:
                urllib.request.urlopen(req)
            self.assertEqual(result.exception.code, 403)
            result.exception.close()
            payload = {'action': 'inventory', 'actor': 'admin', 'ip': '127.0.0.1', 'data': {}}
            req = urllib.request.Request(url, json.dumps(payload).encode(), {'Content-Type': 'application/json', 'Authorization': 'Bearer ' + 'f' * 64})
            with urllib.request.urlopen(req) as response:
                self.assertEqual(json.load(response)['clients'][0]['username'], 'prueba01')
        finally:
            server.shutdown()
            server.server_close()


if __name__ == '__main__':
    unittest.main()
