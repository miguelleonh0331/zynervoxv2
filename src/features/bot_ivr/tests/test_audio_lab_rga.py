"""RGA adapter contract without network calls or real credentials."""
import importlib.util
import io
import json
import tempfile
import unittest
import wave
from pathlib import Path
from unittest.mock import patch
import sys

spec = importlib.util.spec_from_file_location('audio_lab', sys.argv[1])
lab = importlib.util.module_from_spec(spec)
spec.loader.exec_module(lab)
del sys.argv[1]


def wav_bytes(channels=1):
    buffer = io.BytesIO()
    with wave.open(buffer, 'wb') as audio:
        audio.setnchannels(channels)
        audio.setsampwidth(2)
        audio.setframerate(8000)
        audio.writeframes(b'\0' * 1600)
    return buffer.getvalue()


class Response:
    def __init__(self, status=200, data=None):
        self.status_code = status
        self.data = wav_bytes() if data is None else data

    def __enter__(self):
        return self

    def __exit__(self, *_):
        pass

    def iter_content(self, chunk_size):
        yield self.data


class RgaTests(unittest.TestCase):
    def setUp(self):
        self.directory = tempfile.TemporaryDirectory()
        self.addCleanup(self.directory.cleanup)
        self.config = Path(self.directory.name) / 'config.json'
        self.config.write_text(json.dumps({'endpoint': 'http://gateway.invalid/v1/audio/speech', 'token': 'fake-test-token'}))
        self.output = Path(self.directory.name) / 'sample.wav'
        self.config_patch = patch.object(lab, 'RGA_CONFIG', self.config)
        self.config_patch.start()
        self.addCleanup(self.config_patch.stop)

    def test_audio_and_request(self):
        with patch.object(lab.requests, 'post', return_value=Response()) as post:
            lab.generate_rga('Hola', self.output)
            kwargs = post.call_args.kwargs
            self.assertEqual(kwargs['json'], {'input': 'Hola', 'language': 'es', 'speed': 1.3, 'format': 'wav'})
            self.assertFalse(kwargs['allow_redirects'])
            self.assertTrue(kwargs['stream'])
            self.assertEqual(self.output.read_bytes(), wav_bytes())

    def test_status_mapping(self):
        for status, code in [(401, 'rga_auth'), (502, 'rga_proxy_failed'), (503, 'rga_unavailable'), (302, 'rga_error')]:
            with self.subTest(status=status), patch.object(lab.requests, 'post', return_value=Response(status)):
                with self.assertRaises(lab.RgaError) as result:
                    lab.generate_rga('Hola', self.output)
                self.assertEqual(result.exception.code, code)

    def test_wrong_audio(self):
        for data in [b'invalid', wav_bytes(channels=2)]:
            with patch.object(lab.requests, 'post', return_value=Response(data=data)):
                with self.assertRaises(lab.RgaError) as result:
                    lab.generate_rga('Hola', self.output)
                self.assertEqual(result.exception.code, 'rga_invalid_audio')

    def test_size_limit(self):
        with patch.object(lab, 'MAX_AUDIO_BYTES', 100), patch.object(lab.requests, 'post', return_value=Response()):
            with self.assertRaises(lab.RgaError) as result:
                lab.generate_rga('Hola', self.output)
            self.assertEqual(result.exception.code, 'rga_invalid_audio')

    def test_connection_error(self):
        with patch.object(lab.requests, 'post', side_effect=lab.requests.ConnectionError('private-error')):
            with self.assertRaises(lab.RgaError) as result:
                lab.generate_rga('Hola', self.output)
            self.assertEqual(str(result.exception), 'rga_connection')


if __name__ == '__main__':
    unittest.main()
