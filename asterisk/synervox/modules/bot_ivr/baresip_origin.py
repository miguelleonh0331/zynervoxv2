#!/usr/local/bin/python3.11
"""Root-owned compatibility broker. No credentials or arbitrary commands from PHP."""
import contextlib
import fcntl
import hashlib
import json
import os
from pathlib import Path
import re
import subprocess
import sys
import time

CONFIG = Path('/etc/zynervoxv2/baresip-origin.json')
OWNER = '18446744073709551615'
POOL = 'synervox.dynamic_ivr_pilot_annexes'
LEASE_SECONDS = 90


def command(argv, timeout=12):
    return subprocess.check_output(argv, stderr=subprocess.DEVNULL,
                                   timeout=timeout, universal_newlines=True)


def sql(statement):
    return command(['/usr/bin/mysql', '--batch', '--raw', '--skip-column-names', '-e', statement])


def token(value):
    if not isinstance(value, str) or not re.fullmatch(r'zv2-[a-f0-9]{24}', value):
        raise ValueError('Invalid call token')
    return value


def run_token(value):
    if not isinstance(value, str) or not re.fullmatch(r'[a-f0-9]{32}', value):
        raise ValueError('Invalid run token')
    return value


def lock_id(call_id):
    return int(hashlib.sha256(call_id.encode()).hexdigest()[:15], 16)


class Broker:
    def __init__(self):
        stat = CONFIG.stat()
        if stat.st_uid != 0 or stat.st_mode & 0o022 or CONFIG.is_symlink():
            raise ValueError('Unsafe broker configuration')
        self.cfg = json.loads(CONFIG.read_text())
        self.root = Path(self.cfg['leases'])
        if self.root.resolve() != self.root or self.root.stat().st_uid != 0 or self.root.stat().st_mode & 0o022:
            raise ValueError('Unsafe lease directory')
        self.annexes = [str(x) for x in self.cfg['annexes']]
        if not self.annexes or any(not re.fullmatch(r'40[0-9]{2}', x) for x in self.annexes):
            raise ValueError('Invalid annex allowlist')

    @contextlib.contextmanager
    def locked(self):
        with (self.root / '.lock').open('a') as stream:
            fcntl.flock(stream, fcntl.LOCK_EX)
            yield

    def load(self, call_id):
        return json.loads((self.root / (token(call_id) + '.json')).read_text())

    def save(self, lease):
        target = self.root / (token(lease['call_id']) + '.json')
        tmp = target.with_suffix('.tmp')
        with tmp.open('w') as stream:
            os.chmod(str(tmp), 0o600)
            json.dump(lease, stream)
            stream.flush()
            os.fsync(stream.fileno())
        os.replace(str(tmp), str(target))

    def channels(self):
        result = command(['/usr/sbin/asterisk', '-rx', 'core show channels concise'])
        if 'Unable to connect' in result or 'No such command' in result:
            raise ValueError('PBX channel snapshot unavailable')
        return result

    def annex_active(self, annex, channels):
        return any(line.startswith('SIP/' + annex + '-') for line in channels.splitlines())

    def registered(self):
        output = command(['/usr/local/libexec/synervox-zypad-annex-status.sh', '4000', '4050'])
        return set(str(x) for x in json.loads(output)['registered']) & set(self.annexes)

    def release(self, lease, channels):
        if self.annex_active(lease['annex'], channels):
            return False
        sql("UPDATE " + POOL + " SET reserved_campaign_id=NULL,status='free',locked_by=NULL,locked_at=NULL "
            "WHERE annex='" + lease['annex'] + "' AND reserved_campaign_id=" + OWNER +
            " AND locked_by=" + str(lock_id(lease['call_id'])))
        lease['released'] = True
        self.save(lease)
        return True

    def sweep(self, channels):
        # A lost SSH connection can leave remote baresip alive until -t 60 expires.
        for path in self.root.glob('zv2-*.json'):
            lease = self.load(path.stem)
            if lease.get('released'):
                # Keep a bounded audit window without growing the spool forever.
                if time.time() - lease['created'] > 86400:
                    path.unlink()
                    path.with_suffix('.log').unlink(missing_ok=True)
                continue
            expired = time.time() - lease['created'] > LEASE_SECONDS
            if lease['state'] in ('closed', 'failed', 'reserved') and (lease['state'] != 'reserved' or expired):
                if lease['state'] == 'reserved':
                    lease['state'] = 'failed'
                self.release(lease, channels)
            elif expired:
                lease['state'] = 'closed' if lease.get('bound') else 'failed'
                self.release(lease, channels)
        # Recover a crash between the atomic pool update and publishing its ledger.
        known = {str(lock_id(p.stem)) for p in self.root.glob('zv2-*.json')}
        rows = sql("SELECT annex,locked_by FROM " + POOL + " WHERE reserved_campaign_id=" + OWNER +
                   " AND locked_at<DATE_SUB(NOW(),INTERVAL 90 SECOND)")
        for line in rows.splitlines():
            annex, owner = line.split('\t')
            if owner not in known and annex in self.annexes and owner.isdigit() and not self.annex_active(annex, channels):
                sql("UPDATE " + POOL + " SET reserved_campaign_id=NULL,status='free',locked_by=NULL,locked_at=NULL "
                    "WHERE annex='" + annex + "' AND reserved_campaign_id=" + OWNER + " AND locked_by=" + owner)

    def reserve(self, request):
        call_id, run = token(request['call_id']), run_token(request['run_id'])
        if not self.cfg.get('enabled'):
            raise ValueError('Baresip origin disabled')
        active = sql("SELECT run_id FROM zynervox_core.zynervox_bot_dial_runs WHERE run_id='" + run +
                     "' AND dial_prefix='7307' AND state='running'").strip()
        if active != run:
            raise ValueError('Run is not active')
        path = self.root / (call_id + '.json')
        if path.exists():
            raise ValueError('Token already reserved')
        channels = self.channels()
        self.sweep(channels)
        registered = sorted(a for a in self.registered() if not self.annex_active(a, channels))
        if not registered:
            return {'ok': True, 'available': False}
        candidates = ','.join("'" + a + "'" for a in registered)
        key = str(lock_id(call_id))
        output = sql("UPDATE " + POOL + " SET reserved_campaign_id=" + OWNER +
                     ",status='busy',locked_by=" + key + ",locked_at=NOW() WHERE reserved_campaign_id IS NULL "
                     "AND status='free' AND annex IN (" + candidates + ") ORDER BY annex LIMIT 1; "
                     "SELECT annex FROM " + POOL + " WHERE reserved_campaign_id=" + OWNER + " AND locked_by=" + key)
        annex = output.strip()
        if not annex:
            return {'ok': True, 'available': False}
        if annex not in registered:
            raise ValueError('Pool reservation mismatch')
        self.save({'call_id': call_id, 'run_id': run, 'annex': annex, 'state': 'reserved',
                   'created': time.time(), 'bound': False, 'released': False})
        return {'ok': True, 'available': True, 'annex': annex}

    def dial(self, request):
        lease = self.load(request['call_id'])
        if lease['state'] != 'reserved' or lease['released']:
            raise ValueError('Slot already used')
        rows = sql("SELECT a.phone,a.list_id,a.lead_id,r.config_json FROM zynervox_core.zynervox_bot_call_attempts a "
                   "JOIN zynervox_core.zynervox_bot_dial_runs r ON r.run_id=a.dial_run_id "
                   "WHERE a.call_id='" + lease['call_id'] + "' AND r.run_id='" + lease['run_id'] +
                   "' AND r.state IN ('running','stopping') AND a.origin_prefix='7307' AND a.finished_at IS NULL AND a.caller_channel=''").strip()
        if not rows:
            raise ValueError('Claim is not dialable')
        phone, list_id, lead_id, config = rows.split('\t', 3)
        if not re.fullmatch(r'[0-9]{1,20}', phone) or not list_id.isdigit() or not lead_id.isdigit():
            raise ValueError('Invalid claimed lead')
        caller_id = str(json.loads(config).get('caller_id', ''))
        if caller_id and not re.fullmatch(r'[+0-9]{1,20}', caller_id):
            raise ValueError('Invalid caller ID')
        lease.update(phone=phone, list_id=int(list_id), lead_id=int(lead_id), caller_id=caller_id, state='dialing')
        self.save(lease)
        # Only this root-owned script may be executed; no PHP-supplied path or command.
        with (self.root / (lease['call_id'] + '.log')).open('a') as output:
            os.chmod(output.name, 0o600)
            subprocess.Popen(['/usr/local/bin/python3.11', os.path.realpath(__file__), '--run', lease['call_id']],
                             stdin=subprocess.DEVNULL, stdout=output, stderr=output, start_new_session=True,
                             close_fds=True)
        return {'ok': True}

    def bind(self, request):
        lease = self.load(request['call_id'])
        if lease['state'] != 'dialing' or lease['released'] or lease['bound']:
            raise ValueError('Origin token expired or already used')
        if request['phone'] != lease['phone'] or not re.fullmatch('SIP/' + lease['annex'] + r'-[a-fA-F0-9]+', request['channel']):
            raise ValueError('SIP origin does not match reserved annex')
        if not re.fullmatch(r'[0-9]+\.[0-9]+', request['unique_id']):
            raise ValueError('Invalid caller uniqueid')
        lease.update(bound=True, unique_id=request['unique_id'])
        self.save(lease)
        return dict(lease, ok=True)

    def dispatch(self, request):
        action = request.get('action')
        if action == 'check':
            return {'ok': True, 'enabled': bool(self.cfg.get('enabled')), 'registered': len(self.registered())}
        if action == 'reserve':
            return self.reserve(request)
        if action == 'dial':
            return self.dial(request)
        if action == 'bind':
            return self.bind(request)
        if action == 'release':
            lease = self.load(request['call_id'])
            if lease['state'] != 'reserved':
                raise ValueError('Cannot cancel an ambiguous or running origin')
            lease['state'] = 'closed'
            self.release(lease, self.channels())
            return {'ok': True}
        if action == 'poll':
            ids = request.get('calls', [])
            if not isinstance(ids, list) or len(ids) > 100:
                raise ValueError('Invalid poll')
            self.sweep(self.channels())
            leases = [self.load(token(x)) for x in ids]
            return {'ok': True, 'failed': [x['call_id'] for x in leases if x['state'] == 'failed' and not x['bound']],
                    'closed': [x['call_id'] for x in leases if x['released']]}
        raise ValueError('Unknown action')

    def run(self, call_id):
        with self.locked():
            lease = self.load(call_id)
            if lease['state'] != 'dialing' or lease['released'] or time.time() - lease['created'] > LEASE_SECONDS:
                return
        remote = "sudo -n baresip -f /var/lib/zypad_pool_dial/{0} -e '/dial sip:7307{1}*{2}@209.17.220.5' -t 60".format(
            lease['annex'], lease['phone'], lease['call_id'])
        clean_exit = False
        try:
            # Reuse the existing proven SSH identity without copying any secret.
            result = subprocess.run(['/usr/sbin/runuser', '-u', 'wwwrun', '--', '/usr/bin/ssh',
                            '-o', 'BatchMode=yes', '-o', 'ConnectTimeout=5', '-o', 'StrictHostKeyChecking=yes',
                            '-o', 'ServerAliveInterval=5', '-o', 'ServerAliveCountMax=2',
                            '-i', self.cfg['ssh_key'], 'converxa@172.16.10.26', remote],
                           timeout=75, check=False)
            clean_exit = result.returncode == 0
        except (subprocess.TimeoutExpired, OSError):
            pass
        with self.locked():
            lease = self.load(call_id)
            # Even a failed SSH session might have started remote baresip; quarantine to its deadline.
            if not clean_exit and time.time() - lease['created'] < LEASE_SECONDS:
                lease['state'] = 'quarantine'
                self.save(lease)
            else:
                lease['state'] = 'closed' if lease['bound'] else 'failed'
                self.release(lease, self.channels())


def main():
    os.umask(0o077)
    broker = Broker()
    if sys.argv[1:] == ['--sweep']:
        with broker.locked():
            broker.sweep(broker.channels())
        return
    if len(sys.argv) == 3 and sys.argv[1] == '--run':
        broker.run(token(sys.argv[2]))
        return
    if len(sys.argv) != 1:
        raise ValueError('Invalid broker arguments')
    raw = sys.stdin.buffer.read(4097)
    if len(raw) > 4096:
        raise ValueError('Request too large')
    request = json.loads(raw)
    with broker.locked():
        answer = broker.dispatch(request)
    print(json.dumps(answer))


if __name__ == '__main__':
    try:
        main()
    except Exception as error:
        print(json.dumps({'ok': False, 'error': type(error).__name__}))
        sys.exit(1)
