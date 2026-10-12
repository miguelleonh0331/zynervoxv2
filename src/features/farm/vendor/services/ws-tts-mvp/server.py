#!/usr/bin/env python3
"""ws_tts_mvp -- servicio WebSocket para generar audios TTS via un pool de
proxies ya "calientes" (cargados UNA vez al arrancar el proceso, sin el
ciclo de Farm de proceso-nuevo + chequeo de identidad por cada job).

Politica de fallos (confirmada 2026-10-12): si un proxy falla (429, timeout,
cualquier excepcion), el error se reenvia TAL CUAL al cliente -- el cliente
decide si reintenta, el servidor no reintenta solo esa peticion. En paralelo
el proxy que fallo se marca bloqueado de inmediato (un solo strike, sin
umbral) y queda fuera de la rotacion desde la siguiente peticion.

MVP deliberado: sin reintento automatico de bloqueados, sin desbloqueo por
tiempo (upgrade futuro si se necesita, ver proxy_health + auto-retest en
pc_tts_worker_proxy.py / orchestrator.py del Farm grande).

Concurrencia real (2026-10-12): cada generacion corre en su PROPIO subproceso
(gen_worker.py), con el proxy pasado por variables de entorno solo de ese
proceso -- igual que pc_tts_worker_proxy.py en el Farm grande. Asi N jobs
concurrentes usan N proxies distintos de verdad en paralelo (antes estaba
serializado a 1 porque gTTS toma el proxy de HTTP_PROXY/HTTPS_PROXY, vars
GLOBALES del proceso, y un solo proceso no puede llevar dos proxies a la
vez sin pisarse). pick_proxy() ademas excluye proxies ya "procesando" para
no asignar el mismo proxy a dos jobs en vuelo.

Protocolo (por conexion WS), todo JSON salvo el WAV de exito:
  {"text": "...", "lang": "es", "speed": 1.3}
      -> OK: frame binario con el WAV (PCM16 mono 8000Hz)
      -> error: {"ok": false, "error": "..."}
  {"action": "stats"}  -> {"ok": true, "total", "blocked", "available"}
  {"action": "pool"}   -> {"ok": true, "items": [{endpoint, status,
                            country_code, country, city, lat, lon}, ...]}
  {"action": "files"}  -> {"ok": true, "items": [{"name", "account",
                            "uploaded_at"}, ...]}
  {"action": "upload", "account": "...", "filename": "...", "content": "..."}
      -> agrega/reemplaza un archivo de cuentas proxy (texto plano, una
         cuenta por linea) y recarga el pool sin reiniciar el proceso.
      -> {"ok": true} o {"ok": false, "error": "..."}
  {"action": "delete_file", "name": "..."}
      -> borra un archivo de cuentas y recarga el pool.
"""
import asyncio
import json
import os
import re
import secrets
import sys
import time
from pathlib import Path

import requests
import websockets

PROXY_DIR = Path("/opt/zynervox-lab-farm/control/proxy-accounts")
GEN_WORKER = Path(__file__).with_name("gen_worker.py")
HOST = "0.0.0.0"  # escucha en todas las interfaces: LAN 172.16.10.26 y Tailscale 100.82.204.68
PORT = 8900
GTTS_TIMEOUT = 45
GEO_BATCH_URL = "http://ip-api.com/batch?fields=status,countryCode,country,city,lat,lon,query"

# Protege solo el estado compartido del pool (POOL/BLOCKED/STATUS/GEO); la
# generacion en si ya no toma este lock (ver nota de concurrencia arriba).
_lock = asyncio.Lock()

POOL: list[dict] = []
# endpoint -> {"blocked_at": float, "reason": str}
BLOCKED: dict[str, dict] = {}
# endpoint -> "disponible" | "procesando" | "bloqueado"
STATUS: dict[str, str] = {}
# host -> {"country_code", "country", "city", "lat", "lon"} (cacheado; no se
# vuelve a pedir si ya se geolocalizo una vez, sobrevive a reload de pool)
GEO: dict[str, dict] = {}


def parse_proxy_line(line: str) -> dict | None:
    """Acepta los dos formatos presentes en este Farm:
    - "usuario:contrasena@host:puerto" (export de ProxyScrape)
    - "host:puerto:usuario:contrasena" (formato propio del resto del Farm)
    """
    line = line.strip()
    if not line or line.startswith("#"):
        return None
    if "@" in line:
        creds, hostport = line.split("@", 1)
        user, password = creds.split(":", 1)
        host, port = hostport.split(":", 1)
    else:
        parts = line.split(":", 3)
        if len(parts) != 4:
            return None
        host, port, user, password = parts
    host, port = host.strip(), port.strip()
    return {
        "endpoint": f"{host}:{port}",
        "host": host,
        "url": f"http://{user.strip()}:{password.strip()}@{host}:{port}",
    }


def list_account_files() -> list[Path]:
    PROXY_DIR.mkdir(parents=True, exist_ok=True)
    return sorted(
        (p for p in PROXY_DIR.iterdir() if p.is_file() and p.suffix.lower() == ".txt"),
        key=lambda p: p.name.lower(),
    )


def load_proxy_pool() -> list[dict]:
    seen = {}
    for path in list_account_files():
        for raw in path.read_text(encoding="utf-8-sig").splitlines():
            item = parse_proxy_line(raw)
            if item:
                seen[item["endpoint"]] = item  # dedup por endpoint
    return list(seen.values())


def reload_pool() -> None:
    global POOL
    fresh = load_proxy_pool()
    fresh_endpoints = {item["endpoint"] for item in fresh}
    for endpoint in list(STATUS):
        if endpoint not in fresh_endpoints:
            STATUS.pop(endpoint, None)
    for endpoint in list(BLOCKED):
        if endpoint not in fresh_endpoints:
            BLOCKED.pop(endpoint, None)
    for item in fresh:
        STATUS.setdefault(item["endpoint"], "disponible")
    POOL = fresh


def geolocate_missing() -> None:
    """Geolocaliza por lote (1 sola llamada HTTP para hasta 100 IPs) los
    hosts que todavia no tienen cache en GEO. No pasa por el proxy (seria
    lento y requeriria tunel OK): geolocaliza la IP del proxy en si misma,
    que es information publica, vale para ubicarlo en el mapa."""
    hosts = sorted({item["host"] for item in POOL if item["host"] not in GEO})
    if not hosts:
        return
    for batch_start in range(0, len(hosts), 100):
        batch = hosts[batch_start:batch_start + 100]
        try:
            resp = requests.post(GEO_BATCH_URL, json=[{"query": h} for h in batch], timeout=15)
            resp.raise_for_status()
            results = resp.json()
        except Exception as exc:
            print(f"geolocalizacion fallo para {len(batch)} hosts: {exc}", flush=True)
            continue
        for host, result in zip(batch, results):
            if isinstance(result, dict) and result.get("status") == "success":
                GEO[host] = {
                    "country_code": result.get("countryCode"),
                    "country": result.get("country"),
                    "city": result.get("city"),
                    "lat": result.get("lat"),
                    "lon": result.get("lon"),
                }
            else:
                GEO[host] = {"country_code": None, "country": None, "city": None, "lat": None, "lon": None}


def pick_proxy() -> dict:
    # Excluye tambien "procesando": con generacion concurrente (subprocesos),
    # dos jobs en vuelo no deben compartir el mismo proxy.
    available = [
        item for item in POOL
        if item["endpoint"] not in BLOCKED and STATUS.get(item["endpoint"]) != "procesando"
    ]
    if not available:
        raise RuntimeError("no hay proxies libres ahora mismo (todos ocupados o bloqueados)")
    return secrets.choice(available)


def pool_stats() -> dict:
    total = len(POOL)
    blocked = len(BLOCKED)
    return {"ok": True, "total": total, "blocked": blocked, "available": total - blocked}


def pool_items() -> list[dict]:
    items = []
    for item in POOL:
        geo = GEO.get(item["host"], {})
        items.append({
            "endpoint": item["endpoint"],
            "status": STATUS.get(item["endpoint"], "disponible"),
            "country_code": geo.get("country_code"),
            "country": geo.get("country"),
            "city": geo.get("city"),
            "lat": geo.get("lat"),
            "lon": geo.get("lon"),
        })
    return items


_SAFE_NAME_RE = re.compile(r"[^a-zA-Z0-9_.-]+")


def account_from_filename(name: str) -> str:
    # El instalador original nombra los archivos "<timestamp>_<cuenta>.txt";
    # si no trae ese prefijo, se usa el nombre tal cual (sin extension).
    stem = Path(name).stem
    match = re.match(r"^\d{8}-\d{6}_(.+)$", stem)
    return match.group(1) if match else stem


def list_account_entries() -> list[dict]:
    entries = []
    for path in list_account_files():
        entries.append({
            "name": path.name,
            "account": account_from_filename(path.name),
            "uploaded_at": path.stat().st_mtime,
        })
    return entries


class ProxyRateLimited(RuntimeError):
    """Google confirmo 429 para este proxy (gen_worker.py exit 2) -- unico
    caso donde se justifica bloquearlo (ver nota de bloqueo en handle())."""


async def generate_wav_subprocess(text: str, lang: str, speed: float, proxy_url: str) -> bytes:
    """Un proceso por job (ver nota de concurrencia arriba): el proxy va en
    el os.environ de ESTE subproceso nada mas, asi que N jobs concurrentes
    no se pisan entre si y generan en paralelo de verdad."""
    env = dict(os.environ)
    for name in ("HTTP_PROXY", "HTTPS_PROXY", "http_proxy", "https_proxy"):
        env[name] = proxy_url
    proc = await asyncio.create_subprocess_exec(
        sys.executable, str(GEN_WORKER),
        stdin=asyncio.subprocess.PIPE,
        stdout=asyncio.subprocess.PIPE,
        stderr=asyncio.subprocess.PIPE,
        env=env,
    )
    payload = json.dumps({"text": text, "lang": lang, "speed": speed}).encode("utf-8")
    try:
        stdout, stderr = await asyncio.wait_for(proc.communicate(payload), timeout=GTTS_TIMEOUT + 30)
    except asyncio.TimeoutError:
        proc.kill()
        await proc.wait()
        # No confirma 429 -- no bloquea (ver ProxyRateLimited arriba).
        raise RuntimeError(f"timeout generando ({GTTS_TIMEOUT + 30}s)")
    except asyncio.CancelledError:
        # Cliente se desconecto a medio camino: sin esto, gen_worker.py queda
        # huerfano corriendo en background (desperdicia CPU y arrastra el
        # throughput de los demas jobs). Se mata antes de propagar.
        proc.kill()
        try:
            await proc.wait()
        except Exception:
            pass
        raise
    if proc.returncode != 0:
        message = stderr.decode("utf-8", "replace").strip() or f"gen_worker exit {proc.returncode}"
        if proc.returncode == 2:
            raise ProxyRateLimited(message)
        raise RuntimeError(message)
    return stdout


async def handle_action(websocket, data: dict) -> bool:
    """Maneja mensajes de control (no generacion). True si ya respondio."""
    action = data.get("action")
    if action == "stats":
        await websocket.send(json.dumps(pool_stats()))
        return True
    if action == "pool":
        await websocket.send(json.dumps({"ok": True, "items": pool_items()}))
        return True
    if action == "files":
        await websocket.send(json.dumps({"ok": True, "items": list_account_entries()}))
        return True
    if action == "upload":
        filename = str(data.get("filename") or "").strip()
        account = str(data.get("account") or "").strip()
        content = data.get("content")
        if not account or not isinstance(content, str) or not content.strip():
            await websocket.send(json.dumps({"ok": False, "error": "falta cuenta o contenido"}))
            return True
        safe_account = _SAFE_NAME_RE.sub("_", account).strip("_") or "cuenta"
        ts = time.strftime("%Y%m%d-%H%M%S")
        ext = Path(filename).suffix if filename else ".txt"
        dest = PROXY_DIR / f"{ts}_{safe_account}{ext if ext == '.txt' else '.txt'}"
        async with _lock:
            try:
                dest.write_text(content, encoding="utf-8")
                reload_pool()
                geolocate_missing()
            except Exception as exc:
                await websocket.send(json.dumps({"ok": False, "error": f"{type(exc).__name__}: {exc}"}))
                return True
        print(f"archivo subido: {dest.name} ({len(POOL)} proxies en el pool)", flush=True)
        await websocket.send(json.dumps({"ok": True}))
        return True
    if action == "delete_file":
        name = str(data.get("name") or "").strip()
        target = PROXY_DIR / name
        if not name or target.parent != PROXY_DIR or not target.is_file():
            await websocket.send(json.dumps({"ok": False, "error": "archivo invalido"}))
            return True
        async with _lock:
            target.unlink(missing_ok=True)
            reload_pool()
        print(f"archivo eliminado: {name} ({len(POOL)} proxies en el pool)", flush=True)
        await websocket.send(json.dumps({"ok": True}))
        return True
    return False


async def handle(websocket):
    print(f"cliente conectado: {websocket.remote_address}", flush=True)
    async for message in websocket:
        try:
            data = json.loads(message)
        except json.JSONDecodeError as exc:
            await websocket.send(json.dumps({"ok": False, "error": f"JSON invalido: {exc}"}))
            continue

        if await handle_action(websocket, data):
            continue

        try:
            text = str(data.get("text") or "").strip()
            lang = str(data.get("lang") or "es")
            speed = float(data.get("speed") or 1.3)
            if not text:
                raise ValueError("texto vacio")
        except (ValueError, TypeError) as exc:
            await websocket.send(json.dumps({"ok": False, "error": f"payload invalido: {exc}"}))
            continue

        async with _lock:
            try:
                proxy = pick_proxy()
            except RuntimeError as exc:
                await websocket.send(json.dumps({"ok": False, "error": str(exc)}))
                continue
            STATUS[proxy["endpoint"]] = "procesando"

        # Fuera del lock: esto es lo que corre en paralelo entre jobs (ver
        # nota de concurrencia arriba). pick_proxy() ya garantizo que ningun
        # otro job en vuelo tiene este mismo proxy.
        #
        # try/finally (2026-10-12, bug real detectado en produccion): si el
        # cliente corta la conexion a medio camino, esta coroutine se
        # cancela -- CancelledError NO es Exception, "except Exception" no
        # lo atrapaba, y STATUS se quedaba en "procesando" PARA SIEMPRE (el
        # proxy desaparecia de rotacion sin haber fallado nunca). Con 25
        # conexiones cortas en paralelo bastaban unas pocas canceladas para
        # ir encogiendo el pool disponible y degradar el throughput. El
        # finally corre siempre, incluso en cancelacion.
        #
        # Sin "async with _lock" aqui (2026-10-12, segunda vuelta): ese lock
        # agrega su propio punto de "await", y una SEGUNDA cancelacion justo
        # ahi (muy probable con muchas conexiones cortandose a la vez)
        # interrumpia el propio cleanup -- el try/finally no alcanza si lo
        # de adentro tambien puede cancelarse. Una asignacion de diccionario
        # sola no tiene "await": ya es atomica en un event loop de un solo
        # hilo, y al no cancelarse nunca, siempre completa.
        wav_bytes = None
        error = None
        rate_limited = False
        try:
            wav_bytes = await generate_wav_subprocess(text, lang, speed, proxy["url"])
        except Exception as exc:
            error = f"{type(exc).__name__}: {exc}"
            rate_limited = isinstance(exc, ProxyRateLimited)
        finally:
            # Log aca adentro, no despues (2026-10-12, a pedido del usuario
            # antes de una prueba de 300 audios): en cancelacion/desconexion
            # el codigo despues de este finally nunca se alcanza (la
            # CancelledError sigue propagandose), asi que CUALQUIER log
            # puesto ahi se pierde justo para ese caso -- el que mas
            # interesa diagnosticar. Puesto aca, con el "flush=True" de
            # siempre, queda registrado pase lo que pase.
            if wav_bytes is not None:
                STATUS[proxy["endpoint"]] = "disponible"
            elif rate_limited:
                BLOCKED[proxy["endpoint"]] = {"blocked_at": time.time(), "reason": error}
                STATUS[proxy["endpoint"]] = "bloqueado"
                print(f"FAIL via {proxy['endpoint']}: {error} -- proxy bloqueado (429 confirmado)", flush=True)
            else:
                # Cubre fallos normales Y cancelacion/desconexion: el
                # proxy nunca debe quedar colgado en "procesando".
                STATUS[proxy["endpoint"]] = "disponible"
                reason = error or "conexion cancelada/cliente desconectado"
                print(f"FAIL via {proxy['endpoint']}: {reason} -- no bloqueado (fallo transitorio)", flush=True)

        if wav_bytes is None:
            await websocket.send(json.dumps({"ok": False, "error": error or "conexion cancelada"}))
            continue

        print(f"OK via {proxy['endpoint']} ({len(wav_bytes)} bytes) texto={text[:40]!r}", flush=True)
        await websocket.send(wav_bytes)


async def main():
    reload_pool()
    geolocate_missing()
    async with websockets.serve(handle, HOST, PORT, max_size=20 * 1024 * 1024):
        print(
            f"ws_tts_mvp escuchando en ws://{HOST}:{PORT} -- pool: {len(POOL)} proxies cargados",
            flush=True,
        )
        await asyncio.Future()


if __name__ == "__main__":
    asyncio.run(main())
