#!/usr/bin/env python3
"""Real isolated process trees; no TTS network, database or telephone calls."""
import importlib.util
import json
import os
from pathlib import Path
import socket
import subprocess
import sys
import tempfile
import time
import unittest
from unittest.mock import patch
import uuid
from urllib.request import Request, urlopen
from urllib.error import HTTPError
import wave

SOURCE = Path(__file__).resolve().parents[4] / 'asterisk/synervox/modules/bot_ivr/list_audio_worker.py'


class StopTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.root = Path(self.directory.name)
        self.jobs = self.root / 'bot_ivr/audio_jobs'
        self.cache = self.root / 'sounds/cache/ivr_builder/gtts'
        self.jobs.mkdir(parents=True)
        self.cache.mkdir(parents=True)
        fake = self.root / 'fake'
        fake.mkdir()
        (fake / 'gtts.py').write_text('''import os, subprocess, sys, time
from pathlib import Path
class gTTS:
    def __init__(self, text, **kwargs): self.text = text
    def save(self, output):
        if self.text.startswith('conversion'):
            Path(output).write_bytes(b'fixture')
            return
        child = subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(60)'])
        Path(os.environ['AUDIO_TEST_MARKERS']).joinpath(str(os.getpid())).write_text(str(child.pid))
        time.sleep(60)
''')
        converter = self.root / 'ffmpeg-fixture'
        converter.write_text('#!'+sys.executable+'''\nimport os, subprocess, sys, time
from pathlib import Path
child = subprocess.Popen([sys.executable, '-c', 'import time; time.sleep(60)'])
Path(os.environ['AUDIO_TEST_MARKERS']).joinpath(str(os.getpid())).write_text(str(child.pid))
time.sleep(60)
''')
        converter.chmod(0o755)
        self.script = self.root / 'modules/bot_ivr/list_audio_worker.py'
        self.script.parent.mkdir(parents=True)
        self.script.write_text(SOURCE.read_text().replace('/usr/bin/ffmpeg',str(converter)))
        spec = importlib.util.spec_from_file_location('stop_fixture',str(self.script))
        self.worker = importlib.util.module_from_spec(spec)
        spec.loader.exec_module(self.worker)
        self.markers = self.root / 'markers'
        self.markers.mkdir()
        self.env = dict(os.environ,PYTHONPATH=str(fake),AUDIO_TEST_MARKERS=str(self.markers))
        self.active = {}

    def tearDown(self):
        for list_id, token in self.active.items():
            state = self.state(list_id)
            if state.get('state') not in ('ready','stopped'):
                result = self.stop(list_id,token)
                if result.returncode:
                    raise AssertionError('Fixture cleanup failed: '+result.stdout+result.stderr)
        self.directory.cleanup()

    def state(self,list_id):
        return json.loads(self.worker.paths(self.root,list_id).read_text())

    def command(self,*args):
        return subprocess.run([sys.executable,str(self.script),'--root',str(self.root)]+list(args),
                              env=self.env,stdout=subprocess.PIPE,stderr=subprocess.PIPE,universal_newlines=True,timeout=10)

    def launch(self,list_id,text='network pending'):
        token = uuid.uuid4().hex
        path = self.jobs / (token+'.input.json')
        job = {'list_id':list_id,'campaign_id':50,'signature':token,'provider':'gtts','workers':3,
               'prompts':[{'lead_id':1,'node':'cached','text':'Already published'},
                          {'lead_id':1,'node':'pending','text':text+str(list_id)}]}
        path.write_text(json.dumps(job))
        result = self.command('--job',str(path))
        self.assertEqual(result.returncode,0,result.stdout+result.stderr)
        pid = json.loads(result.stdout)['pid']
        self.active[list_id] = token
        return token,pid

    def stop(self,list_id,token,campaign=50):
        return self.command('--stop-list',str(list_id),'--campaign',str(campaign),'--job-id',token)

    def wait(self,condition):
        deadline = time.monotonic()+5
        while not condition():
            if time.monotonic()>deadline: self.fail('Fixture did not become active')
            time.sleep(.03)

    def publish(self):
        def generate(text,path):
            with wave.open(str(path),'wb') as wav:
                wav.setnchannels(1); wav.setframerate(8000); wav.setsampwidth(2); wav.writeframes(b'\0\0'*400)
        self.worker.ensure_audio('Already published',self.cache,generate)

    def test_stop_network_and_conversion_preserves_shared_cache(self):
        self.publish()
        token,pid = self.launch(56)
        other,other_pid = self.launch(57,'conversion pending')
        self.wait(lambda:len(list(self.markers.iterdir()))>=2)
        self.wait(lambda:self.state(56).get('reused')==1)
        children = []
        for marker in self.markers.iterdir(): children.extend([int(marker.name),int(marker.read_text())])
        bad = self.stop(56,uuid.uuid4().hex)
        self.assertNotEqual(bad.returncode,0)
        self.assertNotEqual(self.stop(56,token,51).returncode,0)
        self.assertTrue(self.worker.execution_active(self.root,56))
        saved = self.state(56)
        altered = dict(saved,starttime='stale')
        self.worker.atomic_json(self.worker.paths(self.root,56),altered)
        self.assertNotEqual(self.stop(56,token).returncode,0)
        self.assertNotEqual(self.worker.process_identity(pid)['state'],'T')
        self.worker.atomic_json(self.worker.paths(self.root,56),saved)
        # Failure after parent freeze must thaw it and leave the job retryable.
        original = self.worker.signal_owned
        def fail_child(child_pid,identity,sig):
            if sig == self.worker.signal.SIGSTOP and child_pid != pid: raise ValueError('fixture failure')
            return original(child_pid,identity,sig)
        with patch.object(self.worker,'signal_owned',side_effect=fail_child):
            with self.assertRaises(ValueError): self.worker.stop_job(self.root,56,50,token)
        self.assertNotEqual(self.worker.process_identity(pid)['state'],'T')
        self.assertEqual(self.stop(56,token).returncode,0)
        self.assertEqual(self.state(56)['state'],'stopped')
        self.assertEqual(self.state(56)['reused'],1)
        self.assertFalse(self.worker.execution_active(self.root,56))
        self.assertTrue(self.worker.execution_active(self.root,57))
        self.assertTrue(self.worker.valid_wav(self.cache/(self.worker.audio_hash('Already published')+'.wav')))
        self.assertFalse((self.jobs/(token+'.input.json')).exists())
        self.assertEqual(self.stop(57,other).returncode,0)
        for child in children+[pid,other_pid]:
            self.assertIn((self.worker.process_identity(child) or {}).get('state','Z'),['Z'])
        self.assertEqual(list(self.cache.glob('.*.wav')),[])
        self.assertEqual(self.stop(56,token).returncode,0)
        new,new_pid = self.launch(56)
        self.assertNotEqual(self.stop(56,token).returncode,0)
        self.assertTrue(self.worker.execution_active(self.root,56))
        self.assertEqual(self.stop(56,new).returncode,0)

    def test_stop_immediately_after_launch_has_committed_identity(self):
        self.publish()
        token,pid = self.launch(56)
        state = self.state(56)
        self.assertEqual(state['job_id'],token)
        self.assertEqual(state['pid'],pid)
        self.assertTrue(state['starttime'])
        self.assertEqual(self.stop(56,token).returncode,0)
        self.assertEqual(self.state(56)['state'],'stopped')
        self.assertFalse(self.worker.execution_active(self.root,56))

    def test_http_stop_requires_admin_csrf_scope_and_job(self):
        web = self.root / 'web'
        bot = web / 'bot_ivr'
        bot.mkdir(parents=True)
        source_web = SOURCE.parents[4] / 'app/web'
        for name in ['list_audio_status.php','list_audio_jobs.php','list_audio_service.php']:
            (bot/name).write_bytes((source_web/'bot_ivr'/name).read_bytes())
        (web/'config').mkdir()
        (web/'config/Config.php').write_text('<?php namespace Config; class Config { static function deployment($key,$default=null) { return $key === "runtime" ? '+json.dumps(str(self.root))+' : $default; }}')
        (web/'ivr_builder').mkdir()
        (web/'ivr_builder/published_flow.php').write_text('<?php function ivr_builder_published_flow($id) { throw new RuntimeException("Unused fixture flow"); }')
        (bot/'db.php').write_text('''<?php function bot_ivr_repository() { return new class {
            function list($id,$campaign) { if ($campaign === 52) throw new PDOException('Private DB details'); if ($id !== 56 || $campaign !== 50) throw new RuntimeException('Wrong scope'); return ['id_flujo'=>2]; }
        }; }''')
        (bot/'auth.php').write_text('''<?php function initial_survey_require_login() {
            session_start(); if (($_SERVER['HTTP_X_TEST_ROLE'] ?? '') !== '9') { http_response_code(401); echo '{"ok":false}'; exit; }
            $_SESSION['bot_campaign_csrf'] = 'valid';
        }''')
        executable = web/'venvs/gtts_env/bin/python'
        executable.parent.mkdir(parents=True)
        executable.symlink_to(sys.executable)
        self.publish()
        token,pid = self.launch(56)
        self.wait(lambda:len(list(self.markers.iterdir()))>=1)
        with socket.socket() as listener:
            listener.bind(('127.0.0.1',0)); port = listener.getsockname()[1]
        server = subprocess.Popen(['php','-S','127.0.0.1:'+str(port),'-t',str(web)],env=self.env,
                                  stdout=subprocess.DEVNULL,stderr=subprocess.DEVNULL)
        def connected():
            try:
                connection = socket.create_connection(('127.0.0.1',port),.1); connection.close(); return True
            except OSError: return False
        def request(body=None,role='9',campaign=50):
            url = 'http://127.0.0.1:%d/bot_ivr/list_audio_status.php?id=56&campaign_id=%d' % (port,campaign)
            raw = body.encode() if isinstance(body,str) else json.dumps(body).encode() if body is not None else None
            req = Request(url,data=raw,headers={'X-Test-Role':role,'Content-Type':'application/json'})
            try:
                with urlopen(req,timeout=10) as response: return response.status,json.loads(response.read().decode())
            except HTTPError as error: return error.code,json.loads(error.read().decode())
        try:
            self.wait(connected)
            code,public = request()
            self.assertEqual(code,200)
            self.assertEqual(public['job']['job_id'],token)
            self.assertTrue(public['job']['active'])
            for key in ['pid','starttime','audio','prompts']: self.assertNotIn(key,public['job'])
            body = {'action':'stop','csrf':'valid','job_id':token}
            self.assertEqual(request(body,role='8')[0],401)
            self.assertEqual(request(dict(body,csrf='bad'))[0],403)
            self.assertEqual(request(body,campaign=51)[0],400)
            code,failure = request(body,campaign=52)
            self.assertEqual(code,400)
            self.assertNotIn('Private DB details',failure['error'])
            self.assertEqual(request('{broken')[0],400)
            self.assertEqual(request('x'*5000)[0],400)
            self.assertEqual(request(dict(body,action='other'))[0],400)
            self.assertEqual(request(dict(body,job_id='x'))[0],400)
            self.assertEqual(request(dict(body,job_id=uuid.uuid4().hex))[0],400)
            self.assertTrue(self.worker.execution_active(self.root,56))
            code,result = request(body)
            self.assertEqual(code,200)
            self.assertTrue(result['ok'])
            self.assertEqual(result['job']['state'],'stopped')
            self.assertFalse(result['job']['active'])
            self.assertFalse(result['ready'])
            self.assertFalse(self.worker.execution_active(self.root,56))
        finally:
            server.terminate()
            server.wait(timeout=5)


if __name__ == '__main__':
    unittest.main()
