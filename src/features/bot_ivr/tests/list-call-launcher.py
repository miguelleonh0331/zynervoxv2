import argparse
import fcntl
import importlib.util
from pathlib import Path
import tempfile
import time
from types import SimpleNamespace

parser = argparse.ArgumentParser()
parser.add_argument('launcher')
args = parser.parse_args()
spec = importlib.util.spec_from_file_location('call_launcher_fixture', args.launcher)
module = importlib.util.module_from_spec(spec)
spec.loader.exec_module(module)
with tempfile.TemporaryDirectory(prefix='dial-launcher-') as fixture:
    base = Path(fixture)
    directory = base / 'runtime' / 'bot_ivr' / 'call_jobs'
    directory.mkdir(parents=True)
    worker = base / 'web' / 'bot_ivr' / 'list_call_worker.php'
    worker.parent.mkdir(parents=True)
    worker.write_text("<?php $opts=getopt('', ['list:','campaign:','run:','lease-fd:']); $lease=fopen('php://fd/'.$opts['lease-fd'],'r+'); if(!$lease || !flock($lease,LOCK_EX|LOCK_NB))exit(3); sleep(2); fclose($lease);", encoding='utf-8')
    values = SimpleNamespace(root=str(base / 'runtime'), web=str(base / 'web'), list=1, campaign=1, run='a' * 32)
    module.launch(values)
    with (directory / 'list_1.lock').open('a+') as lease:
        held = False
        try:
            fcntl.flock(lease, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            held = True
        assert held, 'worker lease lost during launch'
    duplicate = False
    try:
        module.launch(values)
    except BlockingIOError:
        duplicate = True
    assert duplicate, 'duplicate worker launched'
    time.sleep(3)
    with (directory / 'list_1.lock').open('a+') as lease:
        fcntl.flock(lease, fcntl.LOCK_EX | fcntl.LOCK_NB)
print('LIST_CALL_LAUNCHER_OK: inherited lease, duplicate rejection and release')
