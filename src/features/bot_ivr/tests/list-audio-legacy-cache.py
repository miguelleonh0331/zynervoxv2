#!/usr/bin/env python3
"""Read-only legacy import and publication; no external TTS or production files."""
from concurrent.futures import ThreadPoolExecutor
import hashlib
import importlib.util
import json
from pathlib import Path
import sqlite3
import tempfile
import unittest
from unittest.mock import Mock, patch
import wave

SOURCE = Path(__file__).resolve().parents[4] / 'asterisk/synervox/modules/bot_ivr/list_audio_worker.py'
spec = importlib.util.spec_from_file_location('legacy_worker', str(SOURCE))
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class LegacyCacheTest(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        root = Path(self.directory.name)
        self.old, self.cache = root / 'old', root / 'new'
        self.old.mkdir(); self.cache.mkdir()
        self.tts = {'key': 'ws:5', 'endpoint': 'ws://127.0.0.1:8900'}
        (self.cache / 'legacy_macelioai.json').write_text(json.dumps({'source': str(self.old), 'providers': [self.tts]}))

    def wav(self, path, frames=b'\x00\x00' * 400):
        with wave.open(str(path), 'wb') as audio:
            audio.setnchannels(1); audio.setsampwidth(2); audio.setframerate(8000)
            audio.writeframes(frames)

    def legacy(self, text='Hola Ana'):
        digest = hashlib.sha256(('macelioai|es|1.3|' + worker.normalize(text)).encode('utf-8')).hexdigest()
        path = self.old / (digest + '.wav')
        self.wav(path)
        return path

    def published(self):
        with sqlite3.connect(str(self.cache / 'audio_registry.sqlite')) as registry:
            return {row[0] for row in registry.execute('SELECT hash FROM published_audio')}

    def generate(self, text, output, tts=None):
        self.wav(output, b'\x01\x00' * 500)

    def test_same_text_imports_under_new_hash_and_preserves_source(self):
        original = self.legacy()
        before = original.read_bytes()
        generator = Mock(side_effect=AssertionError('Remote generation must not run'))
        digest, outcome = worker.ensure_audio('Hola   Ana', self.cache, generator, self.tts)
        self.assertEqual(outcome, 'reused')
        self.assertNotEqual(digest, original.stem)
        self.assertEqual(digest, worker.audio_hash('Hola Ana', self.tts))
        self.assertEqual((self.cache / (digest + '.wav')).read_bytes(), before)
        self.assertEqual(original.read_bytes(), before)
        self.assertEqual(self.published(), {digest})
        generator.assert_not_called()
        # Existing v2 cache takes priority even if legacy configuration disappears.
        (self.cache / 'legacy_macelioai.json').unlink()
        with patch.object(worker, 'copy_macelioai_cache', side_effect=AssertionError('Legacy lookup must not run')):
            self.assertEqual(worker.ensure_audio('Hola Ana', self.cache, generator, self.tts), (digest, 'reused'))

    def test_different_text_or_profile_generates_instead(self):
        self.legacy()
        requests = [
            ('Hola Pedro', self.tts),
            ('Hola Ana', dict(self.tts, key='ws:6')),
            ('Hola Ana', dict(self.tts, endpoint='ws://127.0.0.1:8901')),
            ('Hola Ana', None),
        ]
        for text, tts in requests:
            generator = Mock(side_effect=self.generate)
            digest, outcome = worker.ensure_audio(text, self.cache, generator, tts)
            self.assertEqual(outcome, 'generated')
            generator.assert_called_once()
            self.assertIn(digest, self.published())

    def test_corrupt_source_is_not_published_and_normal_generation_runs(self):
        original = self.legacy()
        original.write_bytes(b'corrupt')
        generator = Mock(side_effect=RuntimeError('provider failed'))
        with self.assertRaises(RuntimeError):
            worker.ensure_audio('Hola Ana', self.cache, generator, self.tts)
        generator.assert_called_once()
        self.assertEqual(self.published(), set())
        self.assertEqual(list(self.cache.glob('*.wav')), [])
        self.assertEqual(original.read_bytes(), b'corrupt')
        digest, outcome = worker.ensure_audio('Hola Ana', self.cache, self.generate, self.tts)
        self.assertEqual(outcome, 'generated')
        self.assertTrue(worker.valid_wav(self.cache / (digest + '.wav')))

    def test_symlink_is_not_used_as_legacy_audio(self):
        original = self.legacy()
        outside = self.old.parent / 'outside.wav'
        original.rename(outside)
        original.symlink_to(outside)
        generator = Mock(side_effect=self.generate)
        self.assertEqual(worker.ensure_audio('Hola Ana', self.cache, generator, self.tts)[1], 'generated')
        generator.assert_called_once()
        self.assertTrue(outside.exists())

    def test_parallel_imports_publish_once_without_generation(self):
        self.legacy()
        generator = Mock(side_effect=AssertionError('Remote generation must not run'))
        with patch.object(worker, 'copy_macelioai_cache', wraps=worker.copy_macelioai_cache) as lookup:
            with ThreadPoolExecutor(max_workers=4) as pool:
                futures = [pool.submit(worker.ensure_audio, 'Hola Ana', self.cache, generator, self.tts) for _ in range(4)]
                results = [future.result() for future in futures]
            self.assertEqual(lookup.call_count, 1)
        self.assertEqual(len(set(results)), 1)
        self.assertEqual(results[0][1], 'reused')
        self.assertEqual(len(self.published()), 1)
        self.assertEqual(len(list(self.cache.glob('*.wav'))), 1)
        generator.assert_not_called()


if __name__ == '__main__':
    unittest.main()
