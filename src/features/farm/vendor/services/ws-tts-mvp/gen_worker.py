#!/usr/bin/env python3
"""Subproceso de generacion para server.py (ver nota "shortcut" alli): gTTS
toma el proxy de HTTP_PROXY/HTTPS_PROXY, variables GLOBALES del proceso -- no
se puede usar un proxy distinto por hilo en el mismo proceso sin pisarse.
Un proceso por job si aisla eso (cada proceso tiene su propio os.environ),
igual que pc_tts_worker_proxy.py en el Farm grande.

Protocolo: recibe {"text","lang","speed"} por stdin (JSON, utf-8). El padre
ya dejo HTTP_PROXY/HTTPS_PROXY seteadas al lanzar este proceso. Escribe el
WAV resultante a stdout (binario). Error -> stderr + exit code:
  exit 2 -> Google confirmo 429 (Too Many Requests) para este proxy: el
            padre SI debe bloquearlo, es la unica senal confiable de que
            el proxy esta quemado de verdad.
  exit 1 -> cualquier otro fallo (conexion, timeout, ffmpeg/sox, payload):
            puede ser transitorio (saturacion momentanea, blip de red) --
            el padre NO bloquea, solo reporta el error y deja el proxy
            disponible para el siguiente intento.

Timeout propio (2026-10-12): gTTS no trae timeout -- si el proxy conecta
pero nunca responde (proxy lento, no roto), la llamada se puede colgar
varias decenas de segundos. Sin esto, un proxy asi queda "disponible" para
siempre (nunca lanza excepcion) y arrastra cualquier lote que lo elija.
Mismo patron que pc_tts_worker_proxy.py del Farm grande: hilo aparte +
future.result(timeout=...). Un timeout NO es 429 -> exit 1, no bloquea.

Distincion 429 vs otros fallos (2026-10-12, a pedido del usuario): antes
cualquier excepcion bloqueaba el proxy para siempre (ver nota en server.py
"un solo strike, sin umbral"). En la practica eso bloqueaba proxies sanos
que solo tuvieron un fallo transitorio (confirmado probando 3 "bloqueados"
a mano: los 3 funcionaban bien). gTTSError trae el status HTTP real en
`exc.rsp.status_code` cuando Google respondio algo; si `rsp` es None fue
un fallo de conexion (no confirma nada sobre el proxy en si).
"""
import json
import os
import subprocess
import sys
import tempfile
from concurrent.futures import ThreadPoolExecutor, TimeoutError as FuturesTimeoutError
from pathlib import Path

FFMPEG = "ffmpeg"
SOX = "sox"
GTTS_TIMEOUT = int(os.getenv("TTS_GTTS_TIMEOUT", "20"))


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

        def _fetch():
            gTTS(text=text, lang=lang, tld="com", slow=False).save(str(mp3_path))

        with ThreadPoolExecutor(max_workers=1) as pool:
            try:
                pool.submit(_fetch).result(timeout=GTTS_TIMEOUT)
            except FuturesTimeoutError:
                raise RuntimeError(f"gTTS no respondio en {GTTS_TIMEOUT}s (proxy probablemente lento/colgado)") from None

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
    except Exception as exc:  # reportado tal cual al padre via stderr; exit code le dice si bloquear
        status_code = getattr(getattr(exc, "rsp", None), "status_code", None)
        print(f"{type(exc).__name__}: {exc}", file=sys.stderr)
        sys.exit(2 if status_code == 429 else 1)
