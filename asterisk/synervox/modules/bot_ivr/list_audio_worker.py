#!/usr/bin/env python3
"""Isolated gTTS list prebuild. No database, CARSA or call-engine access."""
import argparse
from concurrent.futures import ThreadPoolExecutor, as_completed
import fcntl
import hashlib
import inspect
import json
import os
import signal
import sqlite3
from pathlib import Path
import subprocess
import sys
import tempfile
import time
import wave

PROFILE = {'provider': 'gtts', 'voice': 'google-es-com', 'language': 'es',
           'speed': '1.3', 'format': 'pcm_s16le-mono-8000', 'pipeline': 'v1'}
WORKERS = (1, 3, 10, 25, 60, 100)


def remove_file(path):
    try:
        path.unlink()
    except FileNotFoundError:
        pass


def normalize(text):
    if not isinstance(text, str):
        raise ValueError('invalid_text')
    text = ' '.join(text.split())
    if not text or len(text) > 1000 or any(ord(c) < 32 or ord(c) == 127 for c in text):
        raise ValueError('invalid_text')
    if '{' in text or '}' in text:
        raise ValueError('unresolved_variables')
    return text


def audio_hash(text):
    payload = dict(PROFILE, text=normalize(text))
    canonical = json.dumps(payload, ensure_ascii=False, sort_keys=True, separators=(',', ':'))
    return hashlib.sha256(canonical.encode('utf-8')).hexdigest()


def valid_wav(path):
    try:
        size = path.stat().st_size
        if not 100 <= size <= 10 * 1024 * 1024:
            return False
        with wave.open(str(path), 'rb') as audio:
            if (audio.getnchannels(), audio.getframerate(), audio.getsampwidth(), audio.getcomptype()) != (1, 8000, 2, 'NONE'):
                return False
            frames = audio.getnframes()
            return frames > 0 and len(audio.readframes(frames)) == frames * 2
    except (OSError, wave.Error, EOFError):
        return False


def convert_gtts(text, output):
    from gtts import gTTS
    with tempfile.TemporaryDirectory(prefix='zynervox_gtts_') as directory:
        mp3, wav = Path(directory) / 'speech.mp3', Path(directory) / 'speech.wav'
        options = {'text': text, 'lang': 'es', 'tld': 'com', 'slow': False}
        if 'timeout' in inspect.signature(gTTS).parameters:
            options['timeout'] = 25
        gTTS(**options).save(str(mp3))
        subprocess.run(['/usr/bin/ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                        '-i', str(mp3), '-ar', '8000', '-ac', '1', '-sample_fmt', 's16', str(wav)],
                       check=True, timeout=30, stdout=subprocess.PIPE, stderr=subprocess.PIPE)
        subprocess.run(['/usr/bin/sox', str(wav), str(output), 'tempo', '1.3'],
                       check=True, timeout=30, stdout=subprocess.PIPE, stderr=subprocess.PIPE)


def generate_gtts(text, output):
    # gTTS 2.2.x lacks its own timeout; bound the entire conversion externally.
    with subprocess.Popen([sys.executable, str(Path(__file__).resolve()), '--generate-wav', str(output)],
                          stdin=subprocess.PIPE, stdout=subprocess.PIPE, stderr=subprocess.PIPE,
                          universal_newlines=True, start_new_session=True) as process:
        try:
            process.communicate(json.dumps({'text': text}), timeout=100)
        except subprocess.TimeoutExpired:
            os.killpg(process.pid, signal.SIGKILL)
            process.communicate()
            raise RuntimeError('generation_timeout') from None
        if process.returncode:
            raise RuntimeError('generation_failed')


def audio_registry(cache):
    path = cache / 'audio_registry.sqlite'
    connection = sqlite3.connect(str(path), timeout=30)
    connection.execute('PRAGMA journal_mode=WAL')
    connection.execute('CREATE TABLE IF NOT EXISTS published_audio (hash TEXT PRIMARY KEY, published_at INTEGER NOT NULL)')
    connection.commit()
    path.chmod(0o660)
    return connection


def delete_audio(digest, cache):
    # Unpublish first, under the same hash lease used by generation.
    if len(digest) != 64 or any(c not in '0123456789abcdef' for c in digest):
        raise ValueError('invalid_hash')
    with (cache / (digest + '.lock')).open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        with audio_registry(cache) as registry:
            registry.execute('DELETE FROM published_audio WHERE hash=?', (digest,))
        registry.close()
        remove_file(cache / (digest + '.wav'))


def ensure_audio(text, cache, generator=generate_gtts):
    text = normalize(text)
    digest = audio_hash(text)
    target = cache / (digest + '.wav')
    # Keep lock files: unlinking them would allow a second lock inode for one hash.
    with (cache / (digest + '.lock')).open('a') as lock:
        fcntl.flock(lock, fcntl.LOCK_EX)
        registry = audio_registry(cache)
        published = registry.execute('SELECT 1 FROM published_audio WHERE hash=?', (digest,)).fetchone()
        registry.close()
        if published:
            return digest, 'reused'
        fd, temporary = tempfile.mkstemp(prefix='.' + digest + '.', suffix='.wav', dir=str(cache))
        os.close(fd)
        temporary = Path(temporary)
        try:
            generator(text, temporary)
            if not valid_wav(temporary):
                raise ValueError('invalid_audio')
            temporary.chmod(0o640)
            os.replace(str(temporary), str(target))
            with audio_registry(cache) as registry:
                registry.execute('INSERT OR REPLACE INTO published_audio VALUES (?, ?)', (digest, int(time.time())))
            registry.close()
        finally:
            remove_file(temporary)
    return digest, 'generated'


def atomic_json(path, value):
    fd, temporary = tempfile.mkstemp(prefix='.' + path.name, dir=str(path.parent))
    temporary = Path(temporary)
    try:
        with os.fdopen(fd, 'w', encoding='utf-8') as handle:
            json.dump(value, handle, ensure_ascii=False)
        temporary.chmod(0o640)
        os.replace(str(temporary), str(path))
    finally:
        remove_file(temporary)


def read_job(path):
    if path.stat().st_size > 64 * 1024 * 1024:
        raise ValueError('job_too_large')
    job = json.loads(path.read_text(encoding='utf-8'))
    if job.get('provider') != 'gtts' or job.get('workers') not in WORKERS:
        raise ValueError('invalid_provider_or_workers')
    if not isinstance(job.get('list_id'), int) or job['list_id'] < 1:
        raise ValueError('invalid_list')
    if not isinstance(job.get('prompts'), list) or not job['prompts']:
        raise ValueError('empty_prompts')
    for prompt in job['prompts']:
        normalize(prompt['text'])
        if not isinstance(prompt.get('lead_id'), int) or prompt['lead_id'] < 1 or not isinstance(prompt.get('node'), str):
            raise ValueError('invalid_prompt')
    return job


def paths(root, list_id):
    return root / 'bot_ivr' / 'audio_jobs' / ('list_' + str(list_id) + '.json')


def process_identity(pid):
    try:
        proc = Path('/proc') / str(pid)
        uid = proc.stat().st_uid
        if uid != os.geteuid():
            return None
        stat = (proc / 'stat').read_text()
        fields = stat[stat.rfind(')') + 2:].split()
        return {'ppid': int(fields[1]), 'starttime': fields[19], 'state': fields[0],
                'uid': uid, 'cmd': (proc / 'cmdline').read_bytes()}
    except (FileNotFoundError, ProcessLookupError, PermissionError):
        return None


def signal_owned(pid, identity, sig):
    current = process_identity(pid)
    if not current or current['state'] == 'Z':
        return False
    if any(current[key] != identity[key] for key in ('starttime', 'uid', 'cmd')) or current['uid'] != os.geteuid():
        raise ValueError('process_identity_changed')
    try:
        os.kill(pid, sig)
        return True
    except ProcessLookupError:
        return False


def execution_active(root, list_id):
    with (root / 'bot_ivr' / 'audio_jobs' / ('list_' + str(list_id) + '.lock')).open('a') as lease:
        try:
            fcntl.flock(lease, fcntl.LOCK_EX | fcntl.LOCK_NB)
            return False
        except BlockingIOError:
            return True


def stop_job(root, list_id, campaign_id, job_id):
    if list_id < 1 or campaign_id < 1 or len(job_id) != 32 or any(c not in '0123456789abcdef' for c in job_id):
        raise ValueError('invalid_job_identity')
    jobs = root / 'bot_ivr' / 'audio_jobs'
    with (jobs / ('list_' + str(list_id) + '.control.lock')).open('a') as control:
        fcntl.flock(control, fcntl.LOCK_EX)
        status_path = paths(root, list_id)
        state = json.loads(status_path.read_text())
        if state.get('job_id') != job_id or state.get('list_id') != list_id or state.get('campaign_id') != campaign_id:
            raise ValueError('job_changed')
        if state.get('state') in ('ready', 'stopped'):
            return
        path = jobs / (job_id + '.input.json')
        pid = int(state.get('pid', 0))
        identity = process_identity(pid) if pid > 0 else None
        targets, temporary = {}, set()
        try:
            if identity and identity['state'] != 'Z':
                cmd = identity['cmd'].split(b'\0')
                expected = {b'--root': str(root).encode(), b'--job': str(path).encode()}
                matching = str(Path(__file__).resolve()).encode() in cmd
                for option, value in expected.items():
                    matching = matching and option in cmd and cmd.index(option) + 1 < len(cmd) and cmd[cmd.index(option) + 1] == value
                if not matching or identity['starttime'] != state.get('starttime') or identity['uid'] != os.geteuid():
                    raise ValueError('worker_identity_changed')
                targets[pid] = identity
                signal_owned(pid, identity, signal.SIGSTOP)
                # Freeze descendants before discovering the next level; threads cannot spawn new work.
                for _ in range(64):
                    added = False
                    for entry in Path('/proc').iterdir():
                        if not entry.name.isdigit() or int(entry.name) in targets:
                            continue
                        child = process_identity(int(entry.name))
                        if child and child['ppid'] in targets and child['state'] != 'Z':
                            targets[int(entry.name)] = child
                            signal_owned(int(entry.name), child, signal.SIGSTOP)
                            added = True
                    if not added:
                        break
                else:
                    raise ValueError('process_tree_too_deep')
                latest = json.loads(status_path.read_text())
                if latest.get('job_id') != job_id:
                    raise ValueError('job_changed')
                state = latest
                for child in targets.values():
                    args = child['cmd'].split(b'\0')
                    if b'--generate-wav' in args:
                        output = Path(os.fsdecode(args[args.index(b'--generate-wav') + 1]))
                        cache = root / 'sounds' / 'cache' / 'ivr_builder' / 'gtts'
                        if output.parent == cache and output.name.startswith('.') and output.name.endswith('.wav'):
                            temporary.add(output)
                state['state'] = 'stopping'
                state['updated_at'] = int(time.time())
                atomic_json(status_path, state)
                for child_pid, child in targets.items():
                    signal_owned(child_pid, child, signal.SIGTERM)
                    signal_owned(child_pid, child, signal.SIGCONT)
                time.sleep(.5)
                for child_pid, child in targets.items():
                    signal_owned(child_pid, child, signal.SIGKILL)
                deadline = time.monotonic() + 2
                def live_targets():
                    return [p for p in targets if (process_identity(p) or {}).get('state', 'Z') != 'Z']
                while live_targets():
                    if time.monotonic() >= deadline:
                        raise ValueError('worker_not_stopped')
                    time.sleep(.05)
            if execution_active(root, list_id):
                raise ValueError('worker_lease_still_active')
            for output in temporary:
                remove_file(output)
            remove_file(path)
            state.update(state='stopped', errors=[], updated_at=int(time.time()))
            atomic_json(status_path, state)
        finally:
            # A rejected cancellation must never leave a verified live process frozen.
            release_failed = False
            for child_pid, child in targets.items():
                current = process_identity(child_pid)
                if current and current['state'] in ('T', 't') and current['starttime'] == child['starttime'] and current['uid'] == child['uid']:
                    try:
                        signal_owned(child_pid, current, signal.SIGCONT)
                    except (OSError, ValueError):
                        release_failed = True
            if release_failed:
                raise ValueError('could_not_resume_after_failed_stop')


def run_job(root, path, lease_fd):
    # The inherited descriptor holds the per-list lock until this process exits.
    lease = os.fdopen(lease_fd, 'a')
    job = read_job(path)
    status_path = paths(root, job['list_id'])
    cache = root / 'sounds' / 'cache' / 'ivr_builder' / 'gtts'
    state = {'state': 'running', 'list_id': job['list_id'], 'campaign_id': job['campaign_id'],
             'signature': job['signature'], 'provider': 'gtts', 'workers': job['workers'],
             'pid': os.getpid(), 'starttime': process_identity(os.getpid())['starttime'],
             'job_id': path.name.split('.')[0], 'generated': 0, 'reused': 0, 'failed': 0,
             'total': 0, 'completed': 0, 'audio': [], 'errors': [], 'updated_at': int(time.time())}
    try:
        unique = {audio_hash(p['text']): normalize(p['text']) for p in job['prompts']}
        state['total'] = len(unique)
        atomic_json(status_path, state)
        outcomes = {}
        with ThreadPoolExecutor(max_workers=job['workers']) as pool:
            futures = {pool.submit(ensure_audio, text, cache): digest for digest, text in unique.items()}
            for future in as_completed(futures):
                digest = futures[future]
                try:
                    _, outcome = future.result()
                    state[outcome] += 1
                    outcomes[digest] = True
                except Exception:
                    state['failed'] += 1
                    outcomes[digest] = False
                    if len(state['errors']) < 5:
                        state['errors'].append('No se pudo generar el audio ' + digest[:12] + '.')
                state['completed'] += 1
                state['updated_at'] = int(time.time())
                atomic_json(status_path, state)
        state['audio'] = [{'lead_id': p['lead_id'], 'node': p['node'], 'hash': audio_hash(p['text']),
                           'ready': outcomes[audio_hash(p['text'])]} for p in job['prompts']]
        state['state'] = 'failed' if state['failed'] else 'ready'
    except Exception:
        state['state'] = 'failed'
        state['errors'] = ['No se pudo completar la generación de audios.']
    finally:
        state['updated_at'] = int(time.time())
        atomic_json(status_path, state)
        remove_file(path)
        lease.close()


def launch(root, path):
    job = read_job(path)
    with (path.parent / ('list_' + str(job['list_id']) + '.control.lock')).open('a') as control:
        fcntl.flock(control, fcntl.LOCK_EX)
        return launch_locked(root, path, job)


def launch_locked(root, path, job):
    lock = (path.parent / ('list_' + str(job['list_id']) + '.lock')).open('a')
    try:
        fcntl.flock(lock, fcntl.LOCK_EX | fcntl.LOCK_NB)
    except BlockingIOError:
        lock.close()
        raise ValueError('already_running') from None
    gate_read, gate_write = os.pipe()
    process = None
    try:
        with open(os.devnull, 'rb') as stdin, open(os.devnull, 'wb') as output:
            process = subprocess.Popen([sys.executable, str(Path(__file__).resolve()), '--root', str(root),
                         '--job', str(path), '--lease-fd', str(lock.fileno()), '--start-fd', str(gate_read)],
                        stdin=stdin, stdout=output, stderr=output, start_new_session=True,
                        pass_fds=(lock.fileno(), gate_read))
        atomic_json(paths(root, job['list_id']), {'state': 'starting', 'list_id': job['list_id'],
                    'campaign_id': job['campaign_id'], 'job_id': path.name.split('.')[0],
                    'signature': job['signature'], 'pid': process.pid,
                    'starttime': process_identity(process.pid)['starttime'], 'updated_at': int(time.time())})
        os.write(gate_write, b'1')
        return process.pid
    except Exception:
        if process is not None:
            process.kill()
            process.wait()
        raise
    finally:
        os.close(gate_read)
        os.close(gate_write)
        lock.close()


if __name__ == '__main__':
    parser = argparse.ArgumentParser()
    parser.add_argument('--root')
    parser.add_argument('--job')
    parser.add_argument('--generate-wav')
    parser.add_argument('--lease-fd', type=int)
    parser.add_argument('--start-fd', type=int)
    parser.add_argument('--stop-list', type=int)
    parser.add_argument('--campaign', type=int)
    parser.add_argument('--job-id')
    args = parser.parse_args()
    try:
        if args.generate_wav:
            convert_gtts(normalize(json.load(sys.stdin)['text']), Path(args.generate_wav))
            sys.exit(0)
        if args.stop_list is not None:
            stop_job(Path(args.root).resolve(), args.stop_list, args.campaign, args.job_id)
            print(json.dumps({'ok': True}))
            sys.exit(0)
        if not args.root or not args.job:
            raise ValueError('missing_job')
        root, job = Path(args.root).resolve(), Path(args.job).resolve()
        if job.parent != root / 'bot_ivr' / 'audio_jobs' or not job.name.endswith('.input.json'):
            raise ValueError('invalid_job_path')
        if args.lease_fd is None:
            print(json.dumps({'ok': True, 'pid': launch(root, job)}))
        else:
            if args.start_fd is not None:
                if os.read(args.start_fd, 1) != b'1':
                    raise ValueError('launch_not_committed')
                os.close(args.start_fd)
            run_job(root, job, args.lease_fd)
    except Exception:
        print(json.dumps({'ok': False, 'error': 'No se pudo iniciar la generación; comprueba si ya está activa.'}))
        sys.exit(1)
