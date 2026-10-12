#!/usr/bin/env python3
"""Subproceso de generacion para server.py (ver nota "shortcut" alli): gTTS
toma el proxy de HTTP_PROXY/HTTPS_PROXY, variables GLOBALES del proceso -- no
se puede usar un proxy distinto por hilo en el mismo proceso sin pisarse.
Un proceso por job si aisla eso (cada proceso tiene su propio os.environ),
igual que pc_tts_worker_proxy.py en el Farm grande.

Protocolo: recibe {"text","lang","speed"} por stdin (JSON, utf-8). El padre
ya dejo HTTP_PROXY/HTTPS_PROXY seteadas al lanzar este proceso. Escribe el
WAV resultante a stdout (binario). Error -> stderr + exit 1.
"""
import json
import subprocess
import sys
import tempfile
from pathlib import Path

FFMPEG = "ffmpeg"
SOX = "sox"


def main() -> None:
    payload = json.loads(sys.stdin.buffer.read().decode("utf-8"))
    text = str(payload["text"])
    lang = str(payload.get("lang") or "es")
    speed = float(payload.get("speed") or 1.3)

    from gtts import gTTS  # import tardio: que un fallo de proxy/red no tape errores de payload

    with tempfile.TemporaryDirectory(prefix="wstts_job_") as tmpdir:
        mp3_path = Path(tmpdir) / "speech.mp3"
        wav_path = Path(tmpdir) / "speech.wav"
        out_path = Path(tmpdir) / "out.wav"
        gTTS(text=text, lang=lang, tld="com", slow=False).save(str(mp3_path))
        subprocess.run(
            [FFMPEG, "-y", "-hide_banner", "-loglevel", "error",
             "-i", str(mp3_path), "-ar", "8000", "-ac", "1", "-sample_fmt", "s16", str(wav_path)],
            check=True, timeout=60,
        )
        subprocess.run(
            [SOX, str(wav_path), str(out_path), "tempo", str(speed)],
            check=True, timeout=60,
        )
        sys.stdout.buffer.write(out_path.read_bytes())


if __name__ == "__main__":
    try:
        main()
    except Exception as exc:  # reportado tal cual al padre via stderr, el mismo contrato de error que ya usa server.py
        print(f"{type(exc).__name__}: {exc}", file=sys.stderr)
        sys.exit(1)
