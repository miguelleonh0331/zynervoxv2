#!/usr/bin/env python3
"""Proof of concept: JSON on stdin to a telephony WAV; no database access."""
import json
import subprocess
import sys
import tempfile
from pathlib import Path

from gtts import gTTS


def generate(payload):
    text = payload.get('text', '')
    if not isinstance(text, str) or not text.strip() or len(text) > 1000:
        raise ValueError('Texto requerido; máximo 1000 caracteres.')
    if payload.get('provider') != 'gtts':
        raise ValueError('Proveedor no disponible.')
    output = Path(payload['output'])
    with tempfile.TemporaryDirectory(prefix='zynervox_audio_lab_') as directory:
        mp3 = Path(directory) / 'speech.mp3'
        wav = Path(directory) / 'speech.wav'
        gTTS(text=text.strip(), lang='es', tld='com', slow=False, timeout=25).save(str(mp3))
        subprocess.run(['/usr/bin/ffmpeg', '-y', '-hide_banner', '-loglevel', 'error',
                        '-i', str(mp3), '-ar', '8000', '-ac', '1', '-sample_fmt', 's16',
                        str(wav)], check=True, timeout=30, capture_output=True)
        subprocess.run(['/usr/bin/sox', str(wav), str(output), 'tempo', '1.3'],
                       check=True, timeout=30, capture_output=True)
    output.chmod(0o600)


if __name__ == '__main__':
    try:
        generate(json.load(sys.stdin))
        print(json.dumps({'ok': True}))
    except Exception:
        # No provider response or supplied text is returned to the browser.
        print(json.dumps({'ok': False, 'error': 'No se pudo generar el audio. Intenta nuevamente.'}))
        sys.exit(1)
