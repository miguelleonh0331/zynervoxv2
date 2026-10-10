#!/usr/bin/env python3
import importlib.util
from pathlib import Path
import tempfile
from concurrent.futures import ThreadPoolExecutor
import threading
import time
import unittest
from unittest.mock import patch
import wave

SOURCE = Path(__file__).resolve().parents[4] / 'asterisk/synervox/modules/bot_ivr/list_audio_worker.py'
spec = importlib.util.spec_from_file_location('list_audio_worker', str(SOURCE))
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class CacheTest(unittest.TestCase):
    def wav(self, text, output):
        with wave.open(str(output), 'wb') as audio:
            audio.setnchannels(1)
            audio.setsampwidth(2)
            audio.setframerate(8000)
            audio.writeframes(b'\0\0' * 400)

    def test_hash_uses_final_text_and_profile(self):
        self.assertEqual(worker.audio_hash('Hola   Ana'), worker.audio_hash('Hola Ana'))
        self.assertNotEqual(worker.audio_hash('Hola Ana'), worker.audio_hash('Hola Pedro'))
        original = worker.PROFILE['speed']
        try:
            worker.PROFILE['speed'] = '1.0'
            other = worker.audio_hash('Hola Ana')
        finally:
            worker.PROFILE['speed'] = original
        self.assertNotEqual(worker.audio_hash('Hola Ana'), other)

    def test_reuse_and_corrupt_repair(self):
        with tempfile.TemporaryDirectory() as directory:
            cache = Path(directory)
            digest, outcome = worker.ensure_audio('Hola Ana', cache, self.wav)
            self.assertEqual(outcome, 'generated')
            self.assertEqual(worker.ensure_audio('Hola Ana', cache, self.wav)[1], 'reused')
            target = cache / (digest + '.wav')
            target.write_bytes(b'RIFFbroken')
            self.assertEqual(worker.ensure_audio('Hola Ana', cache, self.wav)[1], 'generated')
            self.assertTrue(worker.valid_wav(target))

    def test_parallel_identical_text_generated_once(self):
        count = [0]
        counter_lock = threading.Lock()
        def generate(text, output):
            with counter_lock:
                count[0] += 1
            time.sleep(.05)
            self.wav(text, output)
        with tempfile.TemporaryDirectory() as directory:
            cache = Path(directory)
            with ThreadPoolExecutor(max_workers=3) as pool:
                futures = [pool.submit(worker.ensure_audio, 'Hola Ana', cache, generate) for _ in range(3)]
                results = [f.result()[1] for f in futures]
            self.assertEqual(count[0], 1)
            self.assertEqual(sorted(results), ['generated', 'reused', 'reused'])

    def test_failed_generation_has_no_partial_wav(self):
        def fail(text, output):
            output.write_bytes(b'partial')
            raise RuntimeError('failure')
        with tempfile.TemporaryDirectory() as directory:
            cache = Path(directory)
            with self.assertRaises(RuntimeError):
                worker.ensure_audio('Hola Ana', cache, fail)
            self.assertEqual(list(cache.glob('*.wav')), [])

    def test_old_gtts_without_timeout_is_supported(self):
        class OldGtts:
            def __init__(self, text, lang, tld, slow):
                pass
            def save(self, output):
                Path(output).write_bytes(b'test')
        import types
        fake = types.ModuleType('gtts')
        fake.gTTS = OldGtts
        with tempfile.TemporaryDirectory() as directory, patch.dict('sys.modules', {'gtts': fake}), patch.object(worker.subprocess, 'run') as run:
            worker.convert_gtts('Hola Ana', Path(directory) / 'audio.wav')
            self.assertEqual(run.call_count, 2)
            for call in run.call_args_list:
                self.assertNotIn('capture_output', call[1])
                self.assertEqual(call[1]['timeout'], 30)

    def test_timeout_stops_conversion_process_group(self):
        with patch.object(worker.subprocess, 'Popen') as popen, patch.object(worker.os, 'killpg') as kill:
            process = popen.return_value.__enter__.return_value
            process.pid = 123
            process.communicate.side_effect = [worker.subprocess.TimeoutExpired('gtts', 100), ('', '')]
            with self.assertRaises(RuntimeError):
                worker.generate_gtts('Hola Ana', Path('/tmp/unused.wav'))
            kill.assert_called_once_with(123, worker.signal.SIGKILL)


if __name__ == '__main__':
    unittest.main()
