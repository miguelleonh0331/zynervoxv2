#!/usr/bin/env python3
"""Worker gTTS que usa un proxy HTTP/HTTPS o SOCKS5 solo para generar audio.

Las llamadas al API de trabajos siguen usando la ruta normal de Windows.

Uso interactivo:
    python pc_tts_worker_proxy.py

Uso con argumentos (la contrasena se pedira de forma oculta):
    python pc_tts_worker_proxy.py NOMBRE HOST PUERTO USUARIO

Para ejecucion desatendida se puede definir TTS_PROXY_PASSWORD.
"""

import contextlib
import json
import os
import shutil
import subprocess
import sys
import tempfile
import time
import traceback
from concurrent.futures import ThreadPoolExecutor, TimeoutError as FuturesTimeoutError
from pathlib import Path
from urllib.parse import quote

import requests
from gtts import gTTS

from pc_tts_worker import (
    FFMPEG,
    POLL_IDLE_SECONDS,
    TAKE,
    api_call,
)


IP_LOCATION_URL = os.getenv("TTS_IP_LOCATION_URL", "https://ipwho.is/")
IP_COUNTRY_URL = "https://api.country.is/"
# Backoff progresivo de polling mientras la cola esta vacia: 3 -> 6 -> 12 ->
# 30 -> 60s. Se reinicia a 3s en cuanto hay un trabajo real. Debe coincidir
# con IDLE_AUTO_STOP_SECONDS (suma de esta tupla) en orchestrator.py.
IDLE_BACKOFF_SECONDS = (3, 6, 12, 30, 60)
DEFAULT_PROXY_FILE = Path(__file__).with_name("proxy-accounts") / "cuenta1.txt"
HUMAN_FIRST_NAMES = (
    "Mateo", "Sofia", "Lucas", "Valentina", "Daniel", "Camila", "Martin", "Lucia",
    "Nicolas", "Elena", "Adrian", "Paula", "Samuel", "Clara", "Gabriel", "Julia",
    "Diego", "Emma", "Tomas", "Carla",
)
HUMAN_LAST_NAMES = (
    "Rivera", "Morales", "Castro", "Navarro", "Vega", "Rojas", "Mendoza", "Silva",
    "Torres", "Ortega", "Campos", "Cabrera", "Santos", "Guerrero", "Medina", "Reyes",
    "Fuentes", "Herrera", "Molina", "Suarez",
)
PAUSE_FILE = os.getenv("TTS_WORKER_PAUSE_FILE")
DRAIN_FILE = os.getenv("TTS_WORKER_DRAIN_FILE")


def find_sox():
    configured = os.getenv("SOX_BINARY")
    if configured:
        return configured
    discovered = shutil.which("sox")
    if discovered:
        return discovered
    package_root = Path(os.getenv("LOCALAPPDATA", "")) / "Microsoft" / "WinGet" / "Packages"
    matches = list(package_root.glob("ChrisBagwell.SoX_*/*/sox.exe"))
    if matches:
        return str(matches[0])
    raise FileNotFoundError("SoX no está instalado o no se encuentra en PATH")


def control_file_exists(path):
    return bool(path) and Path(path).exists()


def wait_while_paused():
    """Espera sin tomar trabajos nuevos; devuelve False si se pide drenado."""
    announced = False
    while control_file_exists(PAUSE_FILE):
        if control_file_exists(DRAIN_FILE):
            return False
        if not announced:
            print("CONTROL PAUSED", flush=True)
            announced = True
        time.sleep(1)
    if announced:
        print("CONTROL RESUMED", flush=True)
    return True


def worker_name_for(index):
    offset = max(0, index - 1)
    first_count = len(HUMAN_FIRST_NAMES)
    last_count = len(HUMAN_LAST_NAMES)
    first = HUMAN_FIRST_NAMES[offset % first_count]
    last = HUMAN_LAST_NAMES[(offset // first_count) % last_count]
    generation = offset // (first_count * last_count)
    return f"{first} {last}" if generation == 0 else f"{first} {last} {HUMAN_LAST_NAMES[(generation - 1) % last_count]}"


def build_proxy_url(scheme, host, port, username, password):
    scheme = scheme.lower().strip()
    if scheme not in {"http", "https", "socks5", "socks5h"}:
        raise ValueError("Protocolo invalido: usa http, https, socks5 o socks5h")

    auth = ""
    if username:
        auth = f"{quote(username, safe='')}:{quote(password, safe='')}@"
    return f"{scheme}://{auth}{host}:{int(port)}"


def proxy_dict(proxy_url):
    return {"http": proxy_url, "https": proxy_url}


@contextlib.contextmanager
def proxy_environment(proxy_url):
    """Expone el proxy temporalmente para gTTS y restaura el entorno despues."""
    names = ("HTTP_PROXY", "HTTPS_PROXY", "http_proxy", "https_proxy")
    previous = {name: os.environ.get(name) for name in names}
    try:
        for name in names:
            os.environ[name] = proxy_url
        yield
    finally:
        for name, value in previous.items():
            if value is None:
                os.environ.pop(name, None)
            else:
                os.environ[name] = value


def public_identity_via_proxy(proxy_url, timeout=15):
    """Obtiene IP y geolocalización; conserva un fallback mínimo por país."""
    last_error = None
    for url in (IP_LOCATION_URL, IP_COUNTRY_URL):
        try:
            response = requests.get(url, proxies=proxy_dict(proxy_url), timeout=timeout)
            response.raise_for_status()
            data = response.json()
            ip = str(data.get("ip") or "").strip()
            if not ip:
                raise ValueError("respuesta sin IP")
            if url == IP_COUNTRY_URL:
                country_code = str(data.get("country") or "").strip().upper()
                return {
                    "ip": ip, "country_code": country_code, "country": "", "city": "",
                    "latitude": None, "longitude": None,
                }
            if data.get("success") is False:
                raise ValueError(str(data.get("message") or "geolocalización rechazada"))
            return {
                "ip": ip,
                "country_code": str(data.get("country_code") or "").strip().upper(),
                "country": str(data.get("country") or "").strip(),
                "city": str(data.get("city") or "").strip(),
                "latitude": data.get("latitude"),
                "longitude": data.get("longitude"),
            }
        except (requests.RequestException, ValueError, json.JSONDecodeError) as exc:
            last_error = exc
    raise requests.RequestException(f"No se pudo resolver la identidad pública: {last_error}")


def test_proxy_connectivity(proxy_url):
    """Prueba minima para el boton 'Probar' del panel de proxies bloqueados
    (2026-09-06): solo el paso de gTTS (sin ffmpeg/sox), que es justo el que
    falla cuando Google bloquea la IP del proxy. Lanza si no logra generar
    nada; no sube ni descarta ningun trabajo real de la cola."""
    with tempfile.TemporaryDirectory(prefix="pctts_test_") as tmpdir:
        mp3_path = Path(tmpdir) / "speech.mp3"
        with proxy_environment(proxy_url):
            gTTS(text="prueba de conexion", lang="es", tld="com", slow=False).save(str(mp3_path))
        if not mp3_path.is_file() or mp3_path.stat().st_size == 0:
            raise RuntimeError("gTTS no generó audio a través de este proxy")


def generate_wav(text, lang, speed, proxy_url):
    """2026-09-06: gTTS no trae timeout propio -- si el proxy conecta pero
    nunca responde, la llamada se cuelga indefinidamente y el worker se queda
    "procesando" para siempre (nadie mas lo mata). Se acota con un hilo aparte
    + future.result(timeout): si se pasa, se reporta como fallo normal (cuenta
    para el bloqueo automatico del proxy) y el proceso worker igual termina
    solo tras este intento (TTS_WORKER_MAX_JOBS=1), matando de paso el hilo
    colgado al salir el proceso."""
    gtts_timeout = int(os.getenv("TTS_GTTS_TIMEOUT", "45"))
    with tempfile.TemporaryDirectory(prefix="pctts_proxy_") as tmpdir:
        mp3_path = Path(tmpdir) / "speech.mp3"
        wav_path = Path(tmpdir) / "speech.wav"
        out_path = Path(tmpdir) / "out.wav"

        def _fetch():
            with proxy_environment(proxy_url):
                gTTS(text=text, lang=lang, tld="com", slow=False).save(str(mp3_path))

        with ThreadPoolExecutor(max_workers=1) as pool:
            try:
                pool.submit(_fetch).result(timeout=gtts_timeout)
            except FuturesTimeoutError:
                # El hilo colgado puede no alcanzar a restaurar las variables de
                # proxy (esta bloqueado dentro de proxy_environment(), nunca
                # llega a su "finally"). Se limpian a mano aca: este proceso
                # va a reportar el fallo y salir de todos modos, y el intento
                # siguiente (api_call "fail") debe salir SIN proxy, normal.
                for name in ("HTTP_PROXY", "HTTPS_PROXY", "http_proxy", "https_proxy"):
                    os.environ.pop(name, None)
                raise RuntimeError(f"gTTS no respondió en {gtts_timeout}s (proxy probablemente colgado)") from None

        subprocess.run(
            [
                FFMPEG, "-y", "-hide_banner", "-loglevel", "error",
                "-i", str(mp3_path), "-ar", "8000", "-ac", "1",
                "-sample_fmt", "s16", str(wav_path),
            ],
            check=True,
            timeout=60,
        )
        subprocess.run(
            [
                find_sox(), str(wav_path), str(out_path), "tempo", str(speed),
            ],
            check=True,
            timeout=60,
        )
        return out_path.read_bytes()


def process_job(job, worker_name, proxy_url):
    job_hash = job["job_hash"]
    text = job["text"]
    lang = job.get("lang", "es")
    speed = float(job.get("speed", 1.3))
    print(f"[{time.strftime('%H:%M:%S')}] generando {job_hash[:12]}... ({len(text)} chars)")
    try:
        wav_bytes = generate_wav(text, lang, speed, proxy_url)
        code, response = api_call(
            "submit",
            {"job_hash": job_hash, "worker": worker_name},
            method="POST",
            body=wav_bytes,
        )
        if code == 200:
            print(f"[{time.strftime('%H:%M:%S')}] OK {job_hash[:12]} ({len(wav_bytes)} bytes)")
        else:
            print(f"[{time.strftime('%H:%M:%S')}] submit fallo HTTP {code}: {response[:200]}")
    except Exception as exc:
        error = f"{type(exc).__name__}: {exc}"
        print(f"[{time.strftime('%H:%M:%S')}] FAIL {job_hash[:12]}: {error}")
        api_call(
            "fail",
            {"job_hash": job_hash, "worker": worker_name, "error": error[:400]},
            method="POST",
        )


def run_worker(worker_name, proxy_url, proxy_host):
    try:
        identity = public_identity_via_proxy(proxy_url)
        if not identity["ip"]:
            raise ValueError("El servicio de identidad no devolvió una IP")
    except requests.RequestException as exc:
        raise SystemExit(f"No se pudo conectar mediante el proxy: {exc}") from exc

    print("=" * 60)
    print(" pc_tts_worker_proxy")
    print(f" Proceso     : {worker_name}")
    print(f" Proxy       : {proxy_host}")
    print(f"IDENTITY {json.dumps(identity, ensure_ascii=False, separators=(',', ':'))}", flush=True)
    print(f" IP publica  : {identity['ip']}")
    print(f" Pais        : {identity['country_code'] or '??'}")
    print(" API trabajos: conexion normal (sin proxy)")
    print("=" * 60)

    take = max(1, int(os.getenv("TTS_WORKER_TAKE", str(TAKE))))
    max_jobs = max(0, int(os.getenv("TTS_WORKER_MAX_JOBS", "0")))
    processed_jobs = 0
    # Backoff progresivo mientras no hay trabajo (2026-10-06, a pedido del
    # usuario): sin esto, cada worker activo golpea tts_jobs_api.php cada
    # POLL_IDLE_SECONDS (3s) de forma indefinida aunque la cola lleve horas
    # vacia -- con concurrencia alta eso es carga de fondo constante en
    # mirmidon sin ningun beneficio. Ahora el intervalo sube 3 -> 6 -> 12 ->
    # 30 -> 60s mientras siga vacio, y vuelve a 3s apenas aparece un trabajo
    # real. El orquestador (orchestrator.py, _check_idle_auto_stop) vigila
    # por su cuenta cuanto tiempo lleva la cola realmente vacia y sin ningun
    # worker ocupado; tras el mismo ritmo (111s) detiene todo el motor solo,
    # exigiendo un "Iniciar motor" manual -- este worker no decide eso, solo
    # reduce su propia frecuencia de consulta.
    idle_tier = 0
    while True:
        try:
            if control_file_exists(DRAIN_FILE):
                print("CONTROL DRAINED", flush=True)
                break
            if not wait_while_paused():
                print("CONTROL DRAINED", flush=True)
                break

            code, body = api_call("pull", {"take": take, "worker": worker_name})
            if code != 200:
                print(f"pull fallo HTTP {code}: {body[:200]}")
                time.sleep(POLL_IDLE_SECONDS * 3)
                continue

            data = json.loads(body.decode("utf-8"))
            if data.get("ok") is False:
                print(f"pull rechazo API: {data.get('error', 'respuesta no válida')}", flush=True)
                time.sleep(POLL_IDLE_SECONDS * 3)
                continue
            jobs = data.get("jobs", [])
            if not jobs:
                wait_seconds = IDLE_BACKOFF_SECONDS[idle_tier]
                print(
                    f"[{time.strftime('%H:%M:%S')}] HEARTBEAT idle backoff={wait_seconds}s "
                    f"tier={idle_tier + 1}/{len(IDLE_BACKOFF_SECONDS)}",
                    flush=True,
                )
                time.sleep(wait_seconds)
                idle_tier = min(idle_tier + 1, len(IDLE_BACKOFF_SECONDS) - 1)
                continue

            idle_tier = 0
            for job in jobs:
                process_job(job, worker_name, proxy_url)
                processed_jobs += 1
                if max_jobs and processed_jobs >= max_jobs:
                    print("CONTROL ROTATE", flush=True)
                    return
                if control_file_exists(DRAIN_FILE):
                    print("CONTROL DRAINED", flush=True)
                    return
                if control_file_exists(PAUSE_FILE):
                    break
        except KeyboardInterrupt:
            print("Detenido por el usuario.")
            break
        except Exception:
            traceback.print_exc()
            time.sleep(POLL_IDLE_SECONDS * 3)


def load_proxies(path):
    if not path.is_file():
        raise SystemExit(f"No se encontro la lista de proxies: {path}")

    proxies = []
    for line_number, raw_line in enumerate(path.read_text(encoding="utf-8-sig").splitlines(), 1):
        line = raw_line.strip()
        if not line:
            continue
        parts = line.split(":", 3)
        if len(parts) != 4:
            raise SystemExit(
                f"Formato invalido en la linea {line_number}. "
                "Se esperaba IP:PUERTO:USUARIO:CONTRASENA"
            )
        host, port, username, password = parts
        proxies.append((host, port, username, password))

    if not proxies:
        raise SystemExit(f"La lista esta vacia: {path}")
    return proxies


def main():
    args = sys.argv[1:]

    if args and args[0] == "--test-connectivity":
        # Modo aislado para "Probar"/"Reintentar todos" del panel (2026-09-06).
        # CRITICO: tiene que correr en su PROPIO proceso, igual que un job real.
        # proxy_environment() cambia variables de entorno GLOBALES del proceso
        # (es la unica forma de pasarle un proxy a gTTS, no acepta parametro
        # proxies=). Si esto se llama desde un hilo dentro del orquestador
        # (que es un proceso compartido, de larga vida, con hilos concurrentes
        # y que ademas arranca workers nuevos heredando su os.environ), una
        # prueba se puede quedar las variables de proxy puestas y CUALQUIER
        # worker nuevo que arranque en ese instante heredaria un proxy roto
        # para su conexion normal.
        host = os.getenv("TTS_PROXY_HOST", "")
        port = os.getenv("TTS_PROXY_PORT", "")
        username = os.getenv("TTS_PROXY_USERNAME", "")
        password = os.getenv("TTS_PROXY_PASSWORD", "")
        scheme = os.getenv("TTS_PROXY_SCHEME", "http")
        try:
            proxy_url = build_proxy_url(scheme, host, port, username, password)
            test_proxy_connectivity(proxy_url)
        except Exception as exc:
            print(f"FAIL: {exc}", file=sys.stderr)
            raise SystemExit(1)
        print("OK")
        raise SystemExit(0)

    if len(args) > 1:
        print("Uso: python pc_tts_worker_proxy.py [NUMERO_PROXY]")
        raise SystemExit(2)

    selection = args[0] if args else input("\nNumero de proxy: ").strip()
    try:
        proxy_index = int(selection)
    except ValueError:
        raise SystemExit("El numero de proxy debe ser un entero")

    assigned_host = os.getenv("TTS_PROXY_HOST")
    if assigned_host:
        host = assigned_host
        port = os.getenv("TTS_PROXY_PORT", "")
        username = os.getenv("TTS_PROXY_USERNAME", "")
        password = os.getenv("TTS_PROXY_PASSWORD", "")
        if not port or not username or not password:
            raise SystemExit("La asignacion de proxy del orquestador esta incompleta")
    else:
        proxy_file = Path(os.getenv("TTS_PROXY_FILE", str(DEFAULT_PROXY_FILE)))
        proxies = load_proxies(proxy_file)
        print(f"Proxies disponibles: {len(proxies)}\n")
        for index, (proxy_host, proxy_port, _, _) in enumerate(proxies, 1):
            print(f"  [{index:02d}] {proxy_host}:{proxy_port}  ->  {worker_name_for(index)}")
        try:
            host, port, username, password = proxies[proxy_index - 1]
        except IndexError:
            raise SystemExit(f"Proxy invalido. Elige un numero entre 1 y {len(proxies)}")

    scheme = os.getenv("TTS_PROXY_SCHEME", "http")
    worker_name = os.getenv("TTS_WORKER_NAME", worker_name_for(proxy_index))
    proxy_url = build_proxy_url(scheme, host, port, username, password)
    run_worker(worker_name, proxy_url, host)


if __name__ == "__main__":
    main()
