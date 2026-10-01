#!/usr/bin/env python3
"""
pc_tts_worker.py -- worker "pull" para generar audios macelioai (gTTS),
con soporte MULTI-INTERFAZ: permite correr varias copias en la misma PC,
cada una saliendo a internet por una interfaz de red distinta (ej. cable
y WiFi/hotspot, si van por proveedores/redes publicas distintas), para
sumar mas de una IP publica "limpia" al pool sin necesitar otra maquina.

Uso:
  Sin argumentos -- lista las interfaces de red disponibles con su IP
  local y su IP publica (la que veria Google al generar el audio):

      python pc_tts_worker.py

  Con argumentos -- corre el worker en loop infinito, forzando la salida
  de red por la interfaz elegida:

      python pc_tts_worker.py <nombre_proceso> <numero_interfaz>

  Ejemplo (dos copias en paralelo, una por cable, otra por WiFi/hotspot):
      python pc_tts_worker.py pc-diego-cable 1
      python pc_tts_worker.py pc-diego-wifi 2

Requisitos:
  pip install gtts psutil requests
  ffmpeg.exe en el PATH
"""
import os
import socket
import ssl
import subprocess
import sys
import tempfile
import time
import traceback
from pathlib import Path

try:
    from gtts import gTTS
except ImportError:
    raise SystemExit("Falta gtts. Instala con: pip install gtts")

try:
    import psutil
except ImportError:
    raise SystemExit("Falta psutil. Instala con: pip install psutil")

try:
    import requests
    import urllib3
    urllib3.disable_warnings(urllib3.exceptions.InsecureRequestWarning)
    import urllib3.util.connection as urllib3_cn
except ImportError:
    raise SystemExit("Falta requests. Instala con: pip install requests")

# --- Config ---------------------------------------------------------
BASE_URL = os.getenv("TTS_API_URL", "").strip()


def load_auth_token():
    direct = os.getenv("TTS_AUTH_TOKEN", "").strip()
    if direct:
        return direct
    configured = os.getenv("TTS_AUTH_TOKEN_FILE", "").strip()
    token_file = Path(configured) if configured else Path(__file__).with_name("secrets") / "tts_jobs_token"
    try:
        return token_file.read_text(encoding="utf-8").strip()
    except OSError:
        return ""
# Token del pool: siempre externo al repositorio.
AUTH_TOKEN = load_auth_token()
FFMPEG = "ffmpeg"
POLL_IDLE_SECONDS = 3
TAKE = 2
IP_CHECK_HOST = "api.ipify.org"
# ---------------------------------------------------------------------


def list_interfaces():
    """[(nombre_interfaz, ip_local), ...] solo IPv4, sin loopback/APIPA."""
    result = []
    for name, addrs in psutil.net_if_addrs().items():
        for addr in addrs:
            if addr.family == socket.AF_INET and not addr.address.startswith(("127.", "169.254.")):
                result.append((name, addr.address))
    return result


def public_ip_via(local_ip: str, timeout: float = 8) -> str:
    """IP publica vista al salir a internet usando ese IP local como origen.
    Socket crudo, independiente del parche global (sirve para listar
    varias interfaces sin dejar estado pegado)."""
    try:
        s = socket.socket(socket.AF_INET, socket.SOCK_STREAM)
        s.bind((local_ip, 0))
        s.settimeout(timeout)
        s.connect((IP_CHECK_HOST, 443))
        ctx = ssl.create_default_context()
        wrapped = ctx.wrap_socket(s, server_hostname=IP_CHECK_HOST)
        wrapped.send(f"GET / HTTP/1.1\r\nHost: {IP_CHECK_HOST}\r\nConnection: close\r\n\r\n".encode())
        data = b""
        while True:
            chunk = wrapped.recv(4096)
            if not chunk:
                break
            data += chunk
        wrapped.close()
        body = data.split(b"\r\n\r\n", 1)[-1].decode("utf-8", "ignore").strip()
        return body if body else "?"
    except Exception as exc:
        return f"(sin salida: {type(exc).__name__})"


def cmd_list():
    ifaces = list_interfaces()
    if not ifaces:
        print("No se encontraron interfaces IPv4 activas.")
        return
    print("Interfaces disponibles:\n")
    for i, (name, ip) in enumerate(ifaces, 1):
        print(f"  [{i}] {name:<28} ip_local={ip:<16} consultando IP publica...", end="", flush=True)
        pub = public_ip_via(ip)
        print(f"\r  [{i}] {name:<28} ip_local={ip:<16} ip_publica={pub}" + " " * 15)
    print("\nUso: python pc_tts_worker.py <nombre_proceso> <numero_interfaz>")


import contextlib


@contextlib.contextmanager
def bound_interface(local_ip: str):
    """Fuerza SOLO las conexiones abiertas dentro del bloque `with` a
    originarse desde local_ip (afecta requests/urllib3, que es lo que usa
    gTTS). No se aplica a api_call, que sigue la ruta normal del host."""
    original = urllib3_cn.create_connection

    def patched(address, *args, **kwargs):
        kwargs["source_address"] = (local_ip, 0)
        return original(address, *args, **kwargs)

    urllib3_cn.create_connection = patched
    try:
        yield
    finally:
        urllib3_cn.create_connection = original


def api_call(action, params, method="GET", body=None, extra_headers=None):
    if not BASE_URL:
        return 0, b"TTS_API_URL no configurada; integracion externa deshabilitada"
    if not AUTH_TOKEN:
        return 0, b"Falta configurar TTS_AUTH_TOKEN o secrets/tts_jobs_token"
    headers = {"X-Auth-Token": AUTH_TOKEN}
    if extra_headers:
        headers.update(extra_headers)
    try:
        if method == "GET":
            resp = requests.get(f"{BASE_URL}?action={action}", params=params,
                                 headers=headers, timeout=30, verify=False)
        else:
            resp = requests.post(f"{BASE_URL}?action={action}", params=params,
                                  headers=headers, data=body, timeout=30, verify=False)
        return resp.status_code, resp.content
    except requests.exceptions.RequestException as exc:
        return 0, str(exc).encode("utf-8")


def generate_wav(text: str, lang: str, speed: float, local_ip: str) -> bytes:
    with tempfile.TemporaryDirectory(prefix="pctts_") as tmpdir:
        mp3_path = Path(tmpdir) / "speech.mp3"
        wav_path = Path(tmpdir) / "speech.wav"
        out_path = Path(tmpdir) / "out.wav"
        with bound_interface(local_ip):
            gTTS(text=text, lang=lang, tld="com", slow=False).save(str(mp3_path))
        subprocess.run(
            [FFMPEG, "-y", "-hide_banner", "-loglevel", "error",
             "-i", str(mp3_path), "-ar", "8000", "-ac", "1", "-sample_fmt", "s16", str(wav_path)],
            check=True, timeout=60,
        )
        subprocess.run(
            [FFMPEG, "-y", "-hide_banner", "-loglevel", "error",
             "-i", str(wav_path), "-filter:a", f"atempo={speed}", str(out_path)],
            check=True, timeout=60,
        )
        return out_path.read_bytes()


def process_job(job: dict, worker_name: str, local_ip: str) -> None:
    job_hash = job["job_hash"]
    text = job["text"]
    lang = job.get("lang", "es")
    speed = float(job.get("speed", 1.3))
    print(f"[{time.strftime('%H:%M:%S')}] generando {job_hash[:12]}... ({len(text)} chars)")
    try:
        wav_bytes = generate_wav(text, lang, speed, local_ip)
        code, resp = api_call("submit", {"job_hash": job_hash, "worker": worker_name},
                               method="POST", body=wav_bytes)
        if code == 200:
            print(f"[{time.strftime('%H:%M:%S')}] OK {job_hash[:12]} ({len(wav_bytes)} bytes)")
        else:
            print(f"[{time.strftime('%H:%M:%S')}] submit fallo HTTP {code}: {resp[:200]}")
    except Exception as exc:
        err = f"{type(exc).__name__}: {exc}"
        print(f"[{time.strftime('%H:%M:%S')}] FAIL {job_hash[:12]}: {err}")
        api_call("fail", {"job_hash": job_hash, "worker": worker_name, "error": err[:400]}, method="POST")


def run_worker(worker_name: str, iface_name: str, local_ip: str) -> None:
    pub_ip = public_ip_via(local_ip)
    print("=" * 60)
    print(" pc_tts_worker")
    print(f" Interfaz    : {iface_name} ({local_ip})")
    print(f" Proceso     : {worker_name}")
    print(f" IP publica  : {pub_ip}")
    print("=" * 60)
    while True:
        try:
            code, body = api_call("pull", {"take": TAKE, "worker": worker_name})
            if code != 200:
                print(f"pull fallo HTTP {code}: {body[:200]}")
                time.sleep(POLL_IDLE_SECONDS * 3)
                continue
            import json
            data = json.loads(body.decode("utf-8"))
            jobs = data.get("jobs", [])
            if not jobs:
                time.sleep(POLL_IDLE_SECONDS)
                continue
            for job in jobs:
                process_job(job, worker_name, local_ip)
        except KeyboardInterrupt:
            print("Detenido por el usuario.")
            break
        except Exception:
            traceback.print_exc()
            time.sleep(POLL_IDLE_SECONDS * 3)


def main():
    args = sys.argv[1:]
    if len(args) == 0:
        cmd_list()
        return
    if len(args) != 2:
        print("Uso: python pc_tts_worker.py <nombre_proceso> <numero_interfaz>")
        print("     python pc_tts_worker.py    (sin argumentos, lista interfaces)")
        sys.exit(1)
    worker_name, iface_num = args[0], args[1]
    ifaces = list_interfaces()
    try:
        idx = int(iface_num) - 1
        if idx < 0:
            raise ValueError
        iface_name, local_ip = ifaces[idx]
    except (ValueError, IndexError):
        print(f"Interfaz '{iface_num}' invalida. Ejecuta sin argumentos para ver la lista.")
        sys.exit(1)
    run_worker(worker_name, iface_name, local_ip)


if __name__ == "__main__":
    main()
