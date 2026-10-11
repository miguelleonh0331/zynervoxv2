#!/usr/bin/env python3
"""Private OpenVPN administration API. No browser or Docker socket access."""
import csv
from contextlib import contextmanager
import datetime
import fcntl
import hashlib
import hmac
import ipaddress
import json
import os
from pathlib import Path
import re
import socket
import socketserver
import sqlite3
import struct
import subprocess
import threading
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

DATA = Path(os.environ.get('VPN_ADMIN_DATA', '/etc/openvpn/admin'))
PKI = Path(os.environ.get('VPN_PKI', '/etc/openvpn/server/easy-rsa/pki'))
CLIENTS = Path(os.environ.get('VPN_CLIENTS', '/etc/openvpn/clients'))
TOKEN = Path(os.environ.get('VPN_ADMIN_TOKEN', '/run/secrets/vpn-token'))
STATUS = Path('/run/openvpn.status')
LOCK = threading.RLock()
NAME = re.compile(r'[a-zA-Z][a-zA-Z0-9_-]{2,39}\Z')


def now():
    return datetime.datetime.now(datetime.timezone.utc).isoformat(timespec='seconds')


def name(value):
    if not isinstance(value, str) or not NAME.fullmatch(value) or value.lower() in ('server', 'ca'):
        raise ValueError('Identificador inválido: 3–40 letras, números, _ o -; inicia con letra.')
    return value


def fields(data):
    result = {}
    for key, limit in [('display_name', 120), ('email', 160), ('agent_ref', 80), ('notes', 1000)]:
        value = data.get(key, '')
        if not isinstance(value, str) or len(value) > limit or any(ord(c) < 32 and c not in '\n\t' for c in value):
            raise ValueError('Datos de inventario inválidos.')
        result[key] = value.strip()
    if not result['display_name']:
        raise ValueError('El nombre del agente es obligatorio.')
    if result['email'] and not re.fullmatch(r'[^\s@]+@[^\s@]+\.[^\s@]+', result['email']):
        raise ValueError('Correo electrónico inválido.')
    return result


@contextmanager
def db():
    connection = sqlite3.connect(DATA / 'inventory.sqlite3', timeout=15)
    connection.row_factory = sqlite3.Row
    connection.execute('PRAGMA busy_timeout=15000')
    try:
        with connection:
            yield connection
    finally:
        connection.close()


def initialize():
    DATA.mkdir(mode=0o700, parents=True, exist_ok=True)
    os.chmod(DATA, 0o700)
    with db() as c:
        c.executescript('''
        PRAGMA journal_mode=WAL;
        CREATE TABLE IF NOT EXISTS clients (
          username TEXT PRIMARY KEY, display_name TEXT NOT NULL,
          email TEXT NOT NULL DEFAULT '', agent_ref TEXT NOT NULL DEFAULT '', notes TEXT NOT NULL DEFAULT '',
          state TEXT NOT NULL, created_at TEXT NOT NULL, updated_at TEXT NOT NULL,
          last_connected TEXT, last_disconnected TEXT, last_ip TEXT, deleted_at TEXT);
        CREATE TABLE IF NOT EXISTS audit (
          id INTEGER PRIMARY KEY AUTOINCREMENT, time TEXT NOT NULL, actor TEXT NOT NULL,
          ip TEXT NOT NULL, action TEXT NOT NULL, target TEXT NOT NULL,
          result TEXT NOT NULL, details TEXT NOT NULL);
        CREATE TRIGGER IF NOT EXISTS audit_no_update BEFORE UPDATE ON audit
          BEGIN SELECT RAISE(ABORT, 'append-only'); END;
        CREATE TRIGGER IF NOT EXISTS audit_no_delete BEFORE DELETE ON audit
          BEGIN SELECT RAISE(ABORT, 'append-only'); END;
        CREATE INDEX IF NOT EXISTS audit_actor_id ON audit(actor,id);
        CREATE INDEX IF NOT EXISTS audit_target_id ON audit(target,id);
        CREATE INDEX IF NOT EXISTS audit_action_id ON audit(action,id);
        ''')
    os.chmod(DATA / 'inventory.sqlite3', 0o600)


def audit(actor, ip, action, target='', result='OK', details=None, c=None):
    values = (now(), actor, ip, action, target, result, json.dumps(details or {}, ensure_ascii=False))
    if c is not None:
        c.execute('INSERT INTO audit(time,actor,ip,action,target,result,details) VALUES(?,?,?,?,?,?,?)', values)
    else:
        with db() as connection:
            connection.execute('INSERT INTO audit(time,actor,ip,action,target,result,details) VALUES(?,?,?,?,?,?,?)', values)


def pki_states():
    states = {}
    for line in (PKI / 'index.txt').read_text().splitlines():
        parts = line.split('\t')
        if len(parts) < 6:
            continue
        match = re.search(r'(?:^|/)CN=([^/]+)(?:/|$)', parts[5])
        if match and NAME.fullmatch(match[1]) and match[1].lower() not in ('server', 'ca'):
            states[match[1]] = 'active' if parts[0] == 'V' else 'revoked'
    return states


def reconcile():
    states = pki_states()
    with db() as c:
        for username, state in states.items():
            c.execute('INSERT OR IGNORE INTO clients(username,display_name,state,created_at,updated_at) VALUES(?,?,?,?,?)',
                      (username, username, state, now(), now()))
            c.execute('UPDATE clients SET state=?,updated_at=? WHERE username=? AND state<>?', (state, now(), username, state))
        for row in c.execute('SELECT username FROM clients WHERE state="active"').fetchall():
            if row['username'] not in states:
                c.execute('UPDATE clients SET state="unavailable",updated_at=? WHERE username=?', (now(), row['username']))


def online():
    sessions = {}
    try:
        if not STATUS.exists() or (datetime.datetime.now().timestamp() - STATUS.stat().st_mtime) > 25:
            return sessions
        for row in csv.reader(STATUS.read_text().splitlines(), delimiter='\t'):
            if row and row[0] == 'CLIENT_LIST' and len(row) >= 9:
                sessions[row[1]] = {'real_ip': row[2], 'vpn_ip': row[3], 'bytes_received': row[5],
                                    'bytes_sent': row[6], 'connected_since': row[7]}
    except (OSError, ValueError):
        pass
    return sessions


def command(*args):
    try:
        subprocess.run(['/usr/local/bin/ovpn_manage', *args], check=True, timeout=60,
                       stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    except (subprocess.SubprocessError, OSError):
        raise RuntimeError('La operación de certificados no terminó correctamente. Revisa el servidor.') from None


def disconnect(username):
    # CRL rejects reconnects; management kills the existing session immediately.
    with socket.create_connection(('127.0.0.1', 7505), timeout=3) as s:
        s.settimeout(3)
        s.recv(4096)
        s.sendall(('kill ' + username + '\n').encode())
        reply = b''
        while b'\n' not in reply and len(reply) < 4096:
            chunk = s.recv(4096 - len(reply))
            if not chunk:
                break
            reply += chunk
        if not reply.startswith(b'SUCCESS:') and not reply.startswith(b'ERROR: common name'):
            raise RuntimeError('Certificado revocado; no se pudo confirmar la desconexión inmediata.')
        s.sendall(b'quit\n')


def audit_page(data, actor, ip):
    filters, params = [], []
    for key in ('actor', 'target', 'action'):
        value = data.get(key, '')
        if not isinstance(value, str) or len(value) > 80:
            raise ValueError('Filtro inválido.')
        if value:
            filters.append(key + '=?')
            params.append(value)
    cursors = {}
    for key in ('before_id', 'after_id', 'snapshot_id'):
        value = data.get(key)
        if value is not None:
            if type(value) is not int or not 1 <= value <= 9223372036854775807:
                raise ValueError('Cursor inválido.')
            cursors[key] = value
    if 'before_id' in cursors and 'after_id' in cursors:
        raise ValueError('Cursores incompatibles.')
    audit(actor, ip, 'AUDIT_VIEW')
    with db() as c:
        snapshot = cursors.get('snapshot_id') or c.execute('SELECT id FROM audit ORDER BY id DESC LIMIT 1').fetchone()['id']
        filters.append('id<=?')
        params.append(snapshot)
        where = ' AND '.join(filters)
        cursor_clause = ''
        query_params = list(params)
        ascending = 'after_id' in cursors
        if 'before_id' in cursors:
            cursor_clause = ' AND id<?'
            query_params.append(cursors['before_id'])
        elif ascending:
            cursor_clause = ' AND id>?'
            query_params.append(cursors['after_id'])
        rows = c.execute('SELECT * FROM audit WHERE ' + where + cursor_clause +
                         (' ORDER BY id ASC LIMIT 20' if ascending else ' ORDER BY id DESC LIMIT 20'), query_params).fetchall()
        if ascending:
            rows.reverse()
        events = [dict(row) for row in rows]
        older = newer = None
        if rows:
            if c.execute('SELECT id FROM audit WHERE ' + where + ' AND id<? ORDER BY id DESC LIMIT 1', [*params, rows[-1]['id']]).fetchone():
                older = rows[-1]['id']
            if c.execute('SELECT id FROM audit WHERE ' + where + ' AND id>? ORDER BY id ASC LIMIT 1', [*params, rows[0]['id']]).fetchone():
                newer = rows[0]['id']
    return {'audit': events, 'snapshot_id': snapshot, 'before_id': older, 'after_id': newer}


def operate(action, data, actor, ip):
    if action not in ('inventory', 'audit', 'create', 'update', 'revoke', 'delete', 'download', 'access'):
        raise ValueError('Acción inválida.')
    if action == 'audit':
        return audit_page(data, actor, ip)
    with LOCK, open(DATA / 'pki.lock', 'a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        reconcile()
        username = name(data.get('username')) if action in ('create', 'update', 'revoke', 'delete', 'download') else ''
        if action == 'access':
            event = data.get('event')
            if event not in ('PANEL_VIEW', 'ACCESS_DENIED', 'CSRF_DENIED'):
                raise ValueError('Evento inválido.')
            audit(actor, ip, event, result='DENIED' if event.endswith('DENIED') else 'OK')
            return {'ok': True}
        if action == 'inventory':
            audit(actor, ip, 'INVENTORY_VIEW')
            active = online()
            with db() as c:
                clients = [dict(row) for row in c.execute('SELECT * FROM clients WHERE deleted_at IS NULL ORDER BY username')]
                for row in clients:
                    row['session'] = active.get(row['username'])
            return {'clients': clients, 'observed_since': started,
                    'status_available': STATUS.exists() and datetime.datetime.now().timestamp() - STATUS.stat().st_mtime < 25}
        audit(actor, ip, action.upper(), username, 'REQUESTED')
        with db() as c:
            row = c.execute('SELECT * FROM clients WHERE username=?', (username,)).fetchone()
        try:
            if action == 'create':
                metadata = fields(data)
                if row:
                    raise ValueError('El identificador ya existe o fue utilizado anteriormente.')
                command('--addclient', username)
                reconcile()
                with db() as c:
                    c.execute('UPDATE clients SET display_name=?,email=?,agent_ref=?,notes=?,updated_at=? WHERE username=?',
                              (*metadata.values(), now(), username))
            else:
                if not row or row['deleted_at']:
                    raise ValueError('La cuenta no existe en el inventario.')
                if action == 'update':
                    metadata = fields(data)
                    with db() as c:
                        before = {key: row[key] for key in metadata}
                        c.execute('UPDATE clients SET display_name=?,email=?,agent_ref=?,notes=?,updated_at=? WHERE username=?',
                                  (*metadata.values(), now(), username))
                        audit(actor, ip, 'UPDATE_DIFF', username, details={'before': before, 'after': metadata}, c=c)
                elif action in ('revoke', 'delete'):
                    if row['state'] == 'active':
                        command('--revokeclient', username, '-y')
                        reconcile()
                    if pki_states().get(username) == 'active':
                        raise RuntimeError('No se pudo confirmar la revocación.')
                    disconnect(username)
                    if action == 'delete':
                        with db() as c:
                            c.execute('UPDATE clients SET deleted_at=?,updated_at=? WHERE username=?', (now(), now(), username))
                elif action == 'download':
                    if row['state'] != 'active':
                        raise ValueError('Solo se pueden descargar cuentas activas.')
                    profile = CLIENTS / (username + '.ovpn')
                    content = profile.read_text()
                    audit(actor, ip, 'DOWNLOAD', username)
                    return {'profile': content, 'username': username}
            audit(actor, ip, action.upper(), username, details=metadata if action == 'create' else {})
            return {'ok': True}
        except Exception as error:
            audit(actor, ip, action.upper(), username, 'ERROR', {'reason': str(error) if isinstance(error, (ValueError, RuntimeError)) else 'Error interno'})
            raise


class Handler(BaseHTTPRequestHandler):
    def log_message(self, *args):
        pass  # No tokens, profiles, or user data in HTTP logs.

    def reply(self, status, body):
        raw = json.dumps(body, ensure_ascii=False).encode()
        self.send_response(status)
        self.send_header('Content-Type', 'application/json; charset=utf-8')
        self.send_header('Cache-Control', 'no-store')
        self.send_header('Content-Length', str(len(raw)))
        self.end_headers()
        self.wfile.write(raw)

    def do_POST(self):
        self.connection.settimeout(10)
        token = TOKEN.read_text().strip()
        authorization = self.headers.get('Authorization', '')
        if not token or not hmac.compare_digest(authorization, 'Bearer ' + token):
            audit('api', self.client_address[0], 'API_ACCESS_DENIED', result='DENIED')
            return self.reply(403, {'error': 'Acceso denegado.'})
        if self.path != '/api' or self.headers.get('Content-Type') != 'application/json':
            return self.reply(400, {'error': 'Petición inválida.'})
        try:
            length = int(self.headers.get('Content-Length', '0'))
            if not 1 <= length <= 8192:
                raise ValueError('Petición demasiado grande o vacía.')
            data = json.loads(self.rfile.read(length))
            if not isinstance(data, dict):
                raise ValueError('Petición inválida.')
            actor = data.get('actor')
            ip = data.get('ip')
            if not isinstance(actor, str) or not 1 <= len(actor) <= 80 or any(ord(c) < 32 for c in actor):
                raise ValueError('Administrador inválido.')
            ipaddress.ip_address(ip)
            payload = data.get('data', {})
            if not isinstance(payload, dict):
                raise ValueError('Datos inválidos.')
            return self.reply(200, operate(data.get('action'), payload, actor, ip))
        except ValueError as error:
            return self.reply(400, {'error': str(error)})
        except TypeError:
            return self.reply(400, {'error': 'Datos inválidos.'})
        except RuntimeError as error:
            return self.reply(409, {'error': str(error)})
        except Exception:
            return self.reply(503, {'error': 'Servicio VPN no disponible. Revisa el servidor.'})


class EventHandler(socketserver.StreamRequestHandler):
    def handle(self):
        self.request.settimeout(5)
        pid, uid, gid = struct.unpack('3i', self.request.getsockopt(socket.SOL_SOCKET, socket.SO_PEERCRED, 12))
        if uid not in (0, 65534):
            return
        try:
            event = json.loads(self.rfile.readline(4096))
            username = name(event['username'])
            action = event['event']
            if action not in ('VPN_CONNECT', 'VPN_DISCONNECT'):
                return
            ip = str(ipaddress.ip_address(event['ip']))
            # Do not acquire PKI lock: management kill can synchronously invoke this hook.
            with db() as c:
                row = c.execute('SELECT state,deleted_at FROM clients WHERE username=?', (username,)).fetchone()
                if action == 'VPN_CONNECT' and (not row or row['deleted_at'] or pki_states().get(username) != 'active'):
                    audit(username, ip, 'VPN_CONNECT_DENIED', username, 'DENIED', c=c)
                    self.wfile.write(b'DENIED\n')
                    return
                column = 'last_connected' if action == 'VPN_CONNECT' else 'last_disconnected'
                c.execute('UPDATE clients SET ' + column + '=?,last_ip=? WHERE username=?', (now(), ip, username))
                audit(username, ip, action, username, c=c)
            self.wfile.write(b'OK\n')
        except Exception:
            self.wfile.write(b'ERROR\n')


def serve():
    initialize()
    reconcile()
    path = '/run/zv-vpn-events.sock'
    Path(path).unlink(missing_ok=True)
    server = socketserver.ThreadingUnixStreamServer(path, EventHandler)
    os.chmod(path, 0o666)  # Internal container only; SO_PEERCRED validates OpenVPN's UID.
    threading.Thread(target=server.serve_forever, daemon=True).start()
    ThreadingHTTPServer(('0.0.0.0', 8080), Handler).serve_forever()


started = now()
if __name__ == '__main__':
    serve()
