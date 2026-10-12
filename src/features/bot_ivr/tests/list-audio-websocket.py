#!/usr/bin/env python3
"""Real loopback WS protocol and publication, no external TTS or calls."""
import asyncio
import hashlib
import importlib.util
import io
import json
from pathlib import Path
import tempfile
import threading
import unittest
import wave
import websockets

SOURCE = Path(__file__).resolve().parents[4] / 'asterisk/synervox/modules/bot_ivr/list_audio_worker.py'
spec = importlib.util.spec_from_file_location('ws_worker', str(SOURCE))
worker = importlib.util.module_from_spec(spec)
spec.loader.exec_module(worker)


class WebsocketTest(unittest.TestCase):
    @classmethod
    def setUpClass(cls):
        cls.loop = asyncio.new_event_loop()
        cls.requests = []
        cls.started = threading.Event()
        buffer = io.BytesIO()
        with wave.open(buffer, 'wb') as audio:
            audio.setnchannels(1); audio.setsampwidth(2); audio.setframerate(8000)
            audio.writeframes(b'\0\0' * 800)
        cls.wav = buffer.getvalue()
        async def handler(socket, path):
            request = json.loads(await socket.recv())
            cls.requests.append(request)
            if path == '/error':
                await socket.send(json.dumps({'ok': False, 'error': 'fixture error'}))
            elif path == '/invalid':
                await socket.send(b'RIFFnot-a-complete-wave')
            else:
                await socket.send(cls.wav)
        def serve():
            asyncio.set_event_loop(cls.loop)
            cls.server = cls.loop.run_until_complete(websockets.serve(handler, '127.0.0.1', 0))
            cls.port = cls.server.sockets[0].getsockname()[1]
            cls.started.set()
            cls.loop.run_forever()
        cls.thread = threading.Thread(target=serve)
        cls.thread.start()
        if not cls.started.wait(5): raise RuntimeError('Fixture did not start')

    @classmethod
    def tearDownClass(cls):
        async def stop():
            cls.server.close()
            await cls.server.wait_closed()
        asyncio.run_coroutine_threadsafe(stop(), cls.loop).result(5)
        cls.loop.call_soon_threadsafe(cls.loop.stop)
        cls.thread.join(5)
        cls.loop.close()

    def tts(self, path=''):
        return {'key': 'ws:7', 'endpoint': 'ws://127.0.0.1:%d%s' % (self.port, path)}

    def test_protocol_publication_and_reuse(self):
        with tempfile.TemporaryDirectory() as directory:
            cache = Path(directory)
            tts = self.tts()
            count = len(self.requests)
            digest, outcome = worker.ensure_audio('Hola mundo', cache, tts=tts)
            self.assertEqual(outcome, 'generated')
            self.assertTrue(worker.valid_wav(cache / (digest + '.wav')))
            self.assertEqual(self.requests[-1], {'text': 'Hola mundo', 'lang': 'es', 'speed': 1.3})
            self.assertEqual(worker.ensure_audio('Hola mundo', cache, tts=tts), (digest, 'reused'))
            self.assertEqual(len(self.requests), count + 1)
            (cache / (digest + '.wav')).write_bytes(b'broken')
            self.assertEqual(worker.ensure_audio('Hola mundo', cache, tts=tts), (digest, 'generated'))
            self.assertEqual(len(self.requests), count + 2)

    def test_errors_do_not_publish_or_leave_partial_wav(self):
        for path in ['/error', '/invalid']:
            with tempfile.TemporaryDirectory() as directory:
                cache = Path(directory)
                tts = self.tts(path)
                with self.assertRaises(Exception): worker.ensure_audio('Hola mundo', cache, tts=tts)
                self.assertEqual(list(cache.glob('*.wav')), [])
                with worker.audio_registry(cache) as db:
                    self.assertEqual(db.execute('SELECT COUNT(*) FROM published_audio').fetchone()[0], 0)

    def test_profile_identity_and_validation(self):
        tts = self.tts()
        self.assertNotEqual(worker.audio_hash('Hola mundo', tts), worker.audio_hash('Hola mundo'))
        self.assertNotEqual(worker.audio_hash('Hola mundo', tts), worker.audio_hash('Hola mundo', dict(tts, key='ws:8')))
        self.assertEqual(worker.audio_hash('Hola mundo'), '7b2ad86080953389f71a98d021b0a7928533d9799b2433643d85b30d36c3f42e')
        for endpoint in ['http://host', 'ws://user:secret@host', 'ws://host?x=y', 'ws://host#x']:
            with self.assertRaises(ValueError): worker.tts_profile(dict(tts, endpoint=endpoint))


if __name__ == '__main__': unittest.main()
