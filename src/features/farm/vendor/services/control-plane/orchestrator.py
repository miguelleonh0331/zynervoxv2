#!/usr/bin/env python3
"""Control plane local para la flota de workers TTS.

Expone una API HTTP solo en localhost, mantiene el estado en SQLite y es el
único componente autorizado para crear o detener procesos worker.
"""

from __future__ import annotations

import argparse
from concurrent.futures import Future, ThreadPoolExecutor
import hmac
import json
import os
import re
import secrets
import sqlite3
import subprocess
import sys
import threading
import time
from datetime import datetime, timezone
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from pathlib import Path
from urllib.parse import parse_qs, urlparse


FIRST_NAMES = (
    "Mateo", "Sofia", "Lucas", "Valentina", "Daniel", "Camila", "Martin", "Lucia",
    "Nicolas", "Elena", "Adrian", "Paula", "Samuel", "Clara", "Gabriel", "Julia",
    "Diego", "Emma", "Tomas", "Carla", "Bruno", "Irene", "Hugo", "Marta",
    "Marco", "Laura", "Leo", "Sara", "Alex", "Noelia", "Ivan", "Celia",
    "Pablo", "Nora", "David", "Alba", "Javier", "Vera", "Mario", "Alicia",
    "Sergio", "Eva", "Andres", "Lara", "Ruben", "Ines", "Oscar", "Natalia",
    "Raul", "Olivia",
)
LAST_NAMES = (
    "Rivera", "Morales", "Castro", "Navarro", "Vega", "Rojas", "Mendoza", "Silva",
    "Torres", "Ortega", "Campos", "Cabrera", "Santos", "Guerrero", "Medina", "Reyes",
    "Fuentes", "Herrera", "Molina", "Suarez", "Vargas", "Lozano", "Iglesias", "Marin",
    "Soler", "Paredes", "Cortes", "Mendez", "Prieto", "Roman", "Gallego", "Duran",
    "Benitez", "Acosta", "Carrasco", "Miranda", "Ferrer", "Pascual", "Serrano", "Arias",
    "Nunez", "Dominguez", "Vidal", "Calvo", "Bravo", "Esteban", "Lorenzo", "Ramos",
    "Delgado", "Valero",
)
ACTIVE_STATES = {"starting", "idle", "busy", "pausing", "paused", "draining", "restarting", "degraded"}
# Bloqueo de proxies (2026-09-06): fallos seguidos del MISMO proxy (no del
# worker/slot, que puede rotar) antes de excluirlo de la seleccion aleatoria.
# Se resetea a 0 en cada exito; se limpia por completo solo con una prueba
# manual exitosa desde el panel ("Proxies con problemas" -> "Probar").
PROXY_BLOCK_THRESHOLD = 3
GENERATING_RE = re.compile(r"generando\s+([0-9a-fA-F]+)\.\.\.\s+\((\d+) chars\)")
OK_RE = re.compile(r"\]\s+OK\s+([0-9a-fA-F]+)\s+\((\d+) bytes\)")
FAIL_RE = re.compile(r"\]\s+FAIL\s+([0-9a-fA-F]+):\s*(.*)")
IDENTITY_RE = re.compile(r"^IDENTITY\s+(\{.*\})$")


def utc_now() -> str:
    return datetime.now(timezone.utc).isoformat(timespec="seconds")


def worker_name(index: int) -> str:
    """Devuelve un nombre humano estable y único para hasta 125.000 slots."""
    offset = max(0, index - 1)
    first_count = len(FIRST_NAMES)
    last_count = len(LAST_NAMES)
    first = FIRST_NAMES[offset % first_count]
    last = LAST_NAMES[(offset // first_count) % last_count]
    generation = offset // (first_count * last_count)
    if generation == 0:
        return f"{first} {last}"
    second_last = LAST_NAMES[(generation - 1) % last_count]
    return f"{first} {last} {second_last}"


def proxy_directory_signature(path: Path) -> tuple:
    path.mkdir(parents=True, exist_ok=True)
    return tuple(
        (item.name.casefold(), item.stat().st_size, item.stat().st_mtime_ns)
        for item in sorted(path.iterdir(), key=lambda value: value.name.casefold())
        if item.is_file() and item.suffix.casefold() == ".txt"
    )


def read_proxy_directory(path: Path) -> tuple[list[dict], list[str], int]:
    """Carga todos los TXT, valida sus líneas y deduplica por host/puerto."""
    path.mkdir(parents=True, exist_ok=True)
    proxies = []
    files = sorted(
        (item for item in path.iterdir() if item.is_file() and item.suffix.casefold() == ".txt"),
        key=lambda value: value.name.casefold(),
    )
    seen_endpoints = set()
    duplicates = 0
    for file_path in files:
        for line_number, raw in enumerate(file_path.read_text(encoding="utf-8-sig").splitlines(), 1):
            line = raw.strip()
            if not line or line.startswith("#"):
                continue
            parts = [part.strip() for part in line.split(":", 3)]
            if len(parts) != 4 or not all(parts):
                raise RuntimeError(f"Proxy inválido en {file_path.name}, línea {line_number}")
            host, port, username, password = parts
            try:
                numeric_port = int(port)
            except ValueError as exc:
                raise RuntimeError(f"Puerto inválido en {file_path.name}, línea {line_number}") from exc
            if not 1 <= numeric_port <= 65535:
                raise RuntimeError(f"Puerto fuera de rango en {file_path.name}, línea {line_number}")
            endpoint = (host.casefold(), str(numeric_port))
            if endpoint in seen_endpoints:
                duplicates += 1
                continue
            seen_endpoints.add(endpoint)
            proxies.append({
                "host": host,
                "port": str(numeric_port),
                "username": username,
                "password": password,
                "source": file_path.name,
            })
    return proxies, [item.name for item in files], duplicates


class Store:
    def __init__(self, path: Path):
        path.parent.mkdir(parents=True, exist_ok=True)
        self.lock = threading.RLock()
        self.db = sqlite3.connect(path, check_same_thread=False)
        self.db.row_factory = sqlite3.Row
        with self.lock:
            self.db.executescript(
                """
                PRAGMA journal_mode=WAL;
                CREATE TABLE IF NOT EXISTS workers (
                    worker_id INTEGER PRIMARY KEY,
                    name TEXT NOT NULL,
                    desired_state TEXT NOT NULL DEFAULT 'stopped',
                    auto_restart INTEGER NOT NULL DEFAULT 1,
                    jobs_ok INTEGER NOT NULL DEFAULT 0,
                    jobs_failed INTEGER NOT NULL DEFAULT 0,
                    restarts INTEGER NOT NULL DEFAULT 0,
                    last_error TEXT,
                    updated_at TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS events (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    ts TEXT NOT NULL,
                    worker_id INTEGER,
                    level TEXT NOT NULL,
                    kind TEXT NOT NULL,
                    message TEXT NOT NULL,
                    job_hash TEXT
                );
                CREATE INDEX IF NOT EXISTS idx_events_worker_id ON events(worker_id, id DESC);
                CREATE TABLE IF NOT EXISTS settings (
                    key TEXT PRIMARY KEY,
                    value TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS proxy_locations (
                    endpoint TEXT PRIMARY KEY,
                    public_ip TEXT,
                    country_code TEXT,
                    country_name TEXT,
                    city TEXT,
                    latitude REAL,
                    longitude REAL,
                    status TEXT NOT NULL DEFAULT 'pending',
                    last_error TEXT,
                    updated_at TEXT NOT NULL
                );
                CREATE TABLE IF NOT EXISTS proxy_health (
                    endpoint TEXT PRIMARY KEY,
                    consecutive_failures INTEGER NOT NULL DEFAULT 0,
                    blocked INTEGER NOT NULL DEFAULT 0,
                    blocked_at TEXT,
                    blocked_reason TEXT,
                    last_test_at TEXT,
                    last_test_ok INTEGER,
                    last_test_error TEXT,
                    updated_at TEXT NOT NULL
                );
                """
            )
            self.db.commit()

    def ensure_workers(self, workers: list[dict]) -> None:
        with self.lock:
            for item in workers:
                self.db.execute(
                    "INSERT INTO workers(worker_id,name,updated_at) VALUES(?,?,?) "
                    "ON CONFLICT(worker_id) DO UPDATE SET name=excluded.name, updated_at=excluded.updated_at",
                    (item["index"], item["name"], utc_now()),
                )
            self.db.commit()

    def worker_rows(self) -> dict[int, dict]:
        with self.lock:
            return {row["worker_id"]: dict(row) for row in self.db.execute("SELECT * FROM workers")}

    def update_worker(self, worker_id: int, **values) -> None:
        if not values:
            return
        values["updated_at"] = utc_now()
        columns = ", ".join(f"{key}=?" for key in values)
        with self.lock:
            self.db.execute(
                f"UPDATE workers SET {columns} WHERE worker_id=?",
                (*values.values(), worker_id),
            )
            self.db.commit()

    def increment(self, worker_id: int, field: str) -> None:
        if field not in {"jobs_ok", "jobs_failed", "restarts"}:
            raise ValueError("Contador inválido")
        with self.lock:
            self.db.execute(
                f"UPDATE workers SET {field}={field}+1, updated_at=? WHERE worker_id=?",
                (utc_now(), worker_id),
            )
            self.db.commit()

    def reset_counters(self) -> None:
        with self.lock:
            self.db.execute(
                "UPDATE workers SET jobs_ok=0, jobs_failed=0, restarts=0, updated_at=?",
                (utc_now(),),
            )
            self.db.commit()

    def event(self, worker_id: int | None, level: str, kind: str, message: str, job_hash: str | None = None) -> None:
        with self.lock:
            self.db.execute(
                "INSERT INTO events(ts,worker_id,level,kind,message,job_hash) VALUES(?,?,?,?,?,?)",
                (utc_now(), worker_id, level, kind, message[:2000], job_hash),
            )
            self.db.execute("DELETE FROM events WHERE id <= (SELECT MAX(id)-20000 FROM events)")
            self.db.commit()

    def events(self, limit: int = 150, worker_id: int | None = None) -> list[dict]:
        limit = max(1, min(limit, 500))
        with self.lock:
            if worker_id is None:
                rows = self.db.execute("SELECT * FROM events ORDER BY id DESC LIMIT ?", (limit,)).fetchall()
            else:
                rows = self.db.execute(
                    "SELECT * FROM events WHERE worker_id=? ORDER BY id DESC LIMIT ?",
                    (worker_id, limit),
                ).fetchall()
        return [dict(row) for row in reversed(rows)]

    def setting(self, key: str, default: str) -> str:
        with self.lock:
            row = self.db.execute("SELECT value FROM settings WHERE key=?", (key,)).fetchone()
        return row["value"] if row else default

    def set_setting(self, key: str, value: str) -> None:
        with self.lock:
            self.db.execute(
                "INSERT INTO settings(key,value) VALUES(?,?) ON CONFLICT(key) DO UPDATE SET value=excluded.value",
                (key, value),
            )
            self.db.commit()

    def proxy_location(self, endpoint: str) -> dict | None:
        with self.lock:
            row = self.db.execute(
                "SELECT * FROM proxy_locations WHERE endpoint=?",
                (endpoint,),
            ).fetchone()
        return dict(row) if row else None

    def update_proxy_location(self, endpoint: str, **values) -> None:
        values["updated_at"] = utc_now()
        columns = ", ".join(values)
        placeholders = ", ".join("?" for _ in values)
        updates = ", ".join(f"{key}=excluded.{key}" for key in values)
        with self.lock:
            self.db.execute(
                f"INSERT INTO proxy_locations(endpoint,{columns}) VALUES(?,{placeholders}) "
                f"ON CONFLICT(endpoint) DO UPDATE SET {updates}",
                (endpoint, *values.values()),
            )
            self.db.commit()

    def proxy_health(self, endpoint: str) -> dict | None:
        with self.lock:
            row = self.db.execute(
                "SELECT * FROM proxy_health WHERE endpoint=?", (endpoint,)
            ).fetchone()
        return dict(row) if row else None

    def update_proxy_health(self, endpoint: str, **values) -> None:
        values["updated_at"] = utc_now()
        columns = ", ".join(values)
        placeholders = ", ".join("?" for _ in values)
        updates = ", ".join(f"{key}=excluded.{key}" for key in values)
        with self.lock:
            self.db.execute(
                f"INSERT INTO proxy_health(endpoint,{columns}) VALUES(?,{placeholders}) "
                f"ON CONFLICT(endpoint) DO UPDATE SET {updates}",
                (endpoint, *values.values()),
            )
            self.db.commit()

    def blocked_proxies(self) -> list[dict]:
        with self.lock:
            rows = self.db.execute(
                "SELECT * FROM proxy_health WHERE blocked=1 ORDER BY blocked_at DESC"
            ).fetchall()
        return [dict(row) for row in rows]

    def delete_proxy_health(self, endpoint: str) -> None:
        with self.lock:
            self.db.execute("DELETE FROM proxy_health WHERE endpoint=?", (endpoint,))
            self.db.commit()

    def close(self) -> None:
        with self.lock:
            self.db.close()


class Orchestrator:
    def __init__(self, root: Path, data_dir: Path, inventory_probe=None):
        self.root = root
        self.proxy_dir = root / "proxy-accounts"
        self.worker_script = root / "pc_tts_worker_proxy.py"
        self.data_dir = data_dir
        self.control_dir = data_dir / "control"
        self.logs_dir = data_dir / "logs"
        self.control_dir.mkdir(parents=True, exist_ok=True)
        self.logs_dir.mkdir(parents=True, exist_ok=True)
        if not self.worker_script.is_file():
            raise RuntimeError(f"No se encontró el worker: {self.worker_script}")
        self.store = Store(data_dir / "orchestrator.db")
        self.lock = threading.RLock()
        if str(root) not in sys.path:
            sys.path.insert(0, str(root))
        import pc_tts_worker
        self._tts_worker_module = pc_tts_worker
        self.tts_api_call = pc_tts_worker.api_call
        # Si ya habia un valor guardado de una sesion anterior (persistido en
        # settings, sobrevive a un reinicio del orquestador aunque el .env
        # todavia no se haya actualizado), aplicarlo ahora -- pc_tts_worker.BASE_URL
        # solo se lee de TTS_API_URL una vez, al importar el modulo.
        saved_tts_url = self.store.setting("tts_api_url", "")
        if saved_tts_url:
            self._tts_worker_module.BASE_URL = saved_tts_url
        self.queue_stats = {
            "total": 0, "pending": 0, "claimed": 0, "done": 0,
            "failed": 0, "remaining": 0, "available": False,
            "updated_at": None,
        }
        self.queue_next_refresh = 0.0
        self.processes: dict[int, subprocess.Popen] = {}
        self.workers: list[dict] = []
        self.runtime: dict[int, dict] = {}
        self.proxy_files: list[str] = []
        self.proxy_duplicates = 0
        self.proxy_error: str | None = None
        self._proxy_signature: tuple | None = None
        self._proxy_next_refresh = 0.0
        # Reintento automatico de proxies bloqueados (2026-09-06, a pedido del
        # usuario): sin esto un proxy con un fallo pasajero se queda fuera de
        # la rotacion para siempre hasta que alguien lo pruebe a mano.
        self.blocked_retest_next = 0.0
        self._retest_running = False
        inventory_workers = max(1, min(int(os.getenv("TTS_INVENTORY_CONCURRENCY", "16")), 32))
        self.inventory_executor = ThreadPoolExecutor(max_workers=inventory_workers, thread_name_prefix="proxy-inventory")
        self.inventory_futures: dict[str, Future] = {}
        self.inventory_probe = inventory_probe or self._probe_proxy_identity
        self.engine_running = False
        self.closing = False
        self.closed = False
        self._reload_proxy_pool(force=True)
        if self.store.setting("target_workers", "") == "":
            self.store.set_setting("target_workers", str(min(25, len(self.workers))))
        self.manager = threading.Thread(target=self._manager_loop, name="fleet-manager", daemon=True)
        self.manager.start()
        self.store.event(None, "info", "orchestrator", "Control plane iniciado; motor detenido")

    @staticmethod
    def _runtime_state() -> dict:
        return {
            "status": "stopped", "pid": None, "public_ip": None,
            "country_code": None, "country_name": None, "city": None,
            "latitude": None, "longitude": None,
            "location_status": "pending", "location_retry_at": 0.0,
            "current_job": None, "last_activity": None, "last_log": "",
            "started_at": None, "next_restart": 0.0, "restart_pending": False,
            "rotation_ready_at": 0.0,
        }

    @staticmethod
    def _endpoint_key(item: dict) -> tuple[str, str]:
        return item["host"].casefold(), str(item["port"])

    @classmethod
    def _endpoint_id(cls, item: dict) -> str:
        host, port = cls._endpoint_key(item)
        return f"{host}:{port}"

    @staticmethod
    def _valid_coordinates(identity: dict) -> bool:
        try:
            latitude = float(identity.get("latitude"))
            longitude = float(identity.get("longitude"))
        except (TypeError, ValueError):
            return False
        return -90 <= latitude <= 90 and -180 <= longitude <= 180

    def _probe_proxy_identity(self, item: dict) -> dict:
        from pc_tts_worker_proxy import build_proxy_url, public_identity_via_proxy

        proxy_url = build_proxy_url("http", item["host"], item["port"], item["username"], item["password"])
        return public_identity_via_proxy(proxy_url, timeout=10)

    def _hydrate_cached_location(self, item: dict) -> None:
        cached = self.store.proxy_location(self._endpoint_id(item))
        if not cached:
            return
        state = self.runtime[item["index"]]
        state.update(
            public_ip=cached.get("public_ip"),
            country_code=cached.get("country_code"),
            country_name=cached.get("country_name"),
            city=cached.get("city"),
            latitude=cached.get("latitude"),
            longitude=cached.get("longitude"),
            location_status="located" if self._valid_coordinates(cached) else "partial",
            location_retry_at=0.0 if self._valid_coordinates(cached) else time.time() + 60,
        )

    def _schedule_proxy_inventory(self) -> None:
        now = time.time()
        with self.lock:
            for item in self.workers:
                endpoint = self._endpoint_id(item)
                state = self.runtime[item["index"]]
                if endpoint in self.inventory_futures:
                    continue
                if state.get("location_status") == "located":
                    continue
                if state.get("location_retry_at", 0) > now:
                    continue
                state["location_status"] = "probing"
                self.inventory_futures[endpoint] = self.inventory_executor.submit(self.inventory_probe, dict(item))

    def _collect_proxy_inventory(self) -> None:
        completed = []
        with self.lock:
            completed = [
                (endpoint, future)
                for endpoint, future in self.inventory_futures.items()
                if future.done()
            ]
            for endpoint, _future in completed:
                self.inventory_futures.pop(endpoint, None)

        for endpoint, future in completed:
            item = next((candidate for candidate in self.workers if self._endpoint_id(candidate) == endpoint), None)
            try:
                identity = future.result()
                located = self._valid_coordinates(identity)
                values = {
                    "public_ip": str(identity.get("ip") or "").strip() or None,
                    "country_code": str(identity.get("country_code") or "").strip().upper() or None,
                    "country_name": str(identity.get("country") or "").strip() or None,
                    "city": str(identity.get("city") or "").strip() or None,
                    "latitude": float(identity["latitude"]) if located else None,
                    "longitude": float(identity["longitude"]) if located else None,
                    "status": "located" if located else "partial",
                    "last_error": None,
                }
                self.store.update_proxy_location(endpoint, **values)
                if item:
                    with self.lock:
                        self.runtime[item["index"]].update(
                            public_ip=values["public_ip"], country_code=values["country_code"],
                            country_name=values["country_name"], city=values["city"],
                            latitude=values["latitude"], longitude=values["longitude"],
                            location_status=values["status"],
                            location_retry_at=0.0 if located else time.time() + 300,
                        )
            except Exception as exc:
                error = re.sub(r"([a-zA-Z][a-zA-Z0-9+.-]*://)[^@\s]+@", r"\1***@", str(exc))[:500]
                self.store.update_proxy_location(endpoint, status="error", last_error=error)
                if item:
                    with self.lock:
                        self.runtime[item["index"]].update(
                            location_status="error", location_retry_at=time.time() + 60,
                        )

    def _reload_proxy_pool(self, force: bool = False) -> bool:
        if not self.engine_running and self.processes:
            return False
        signature = proxy_directory_signature(self.proxy_dir)
        if not force and signature == self._proxy_signature:
            return False
        try:
            loaded, files, duplicates = read_proxy_directory(self.proxy_dir)
        except (OSError, UnicodeError, RuntimeError) as exc:
            self.proxy_error = str(exc)
            self._proxy_signature = signature
            if hasattr(self, "store"):
                self.store.event(None, "error", "proxy_reload", self.proxy_error)
            return False

        with self.lock:
            current_by_endpoint = {self._endpoint_key(item): item for item in self.workers}
            loaded_by_endpoint = {self._endpoint_key(item): item for item in loaded}
            removed = [key for key in current_by_endpoint if key not in loaded_by_endpoint]

            if self.engine_running and removed:
                # No se retira una credencial que pueda estar en uso. Se aplicará al detener el motor.
                merged = list(self.workers)
                known = set(current_by_endpoint)
                for proxy in loaded:
                    key = self._endpoint_key(proxy)
                    if key in current_by_endpoint:
                        current_by_endpoint[key].update(proxy)
                    elif key not in known:
                        merged.append(proxy)
                        known.add(key)
                loaded = merged

            next_workers = []
            for index, proxy in enumerate(loaded, 1):
                next_workers.append({**proxy, "index": index, "name": worker_name(index)})

            if self.engine_running:
                # Las entradas existentes conservan su índice mientras trabajan; las nuevas se anexan.
                stable = []
                used = set()
                for current in self.workers:
                    incoming = loaded_by_endpoint.get(self._endpoint_key(current))
                    if incoming:
                        current.update(incoming)
                    stable.append(current)
                    used.add(self._endpoint_key(current))
                next_index = max((item["index"] for item in stable), default=0) + 1
                for proxy in loaded:
                    key = self._endpoint_key(proxy)
                    if key in used:
                        continue
                    stable.append({**proxy, "index": next_index, "name": worker_name(next_index)})
                    used.add(key)
                    next_index += 1
                next_workers = stable

            self.workers = next_workers
            active_indexes = {item["index"] for item in self.workers}
            if not self.engine_running:
                self.runtime = {index: self._runtime_state() for index in active_indexes}
            else:
                for index in active_indexes:
                    self.runtime.setdefault(index, self._runtime_state())
            self.store.ensure_workers(self.workers)
            for item in self.workers:
                if not self.runtime[item["index"]].get("public_ip"):
                    self._hydrate_cached_location(item)
            configured_target = int(self.store.setting("target_workers", "0") or "0")
            if configured_target > len(self.workers):
                self.store.set_setting("target_workers", str(len(self.workers)))
            self.proxy_files = files
            self.proxy_duplicates = duplicates
            self.proxy_error = None
            self._proxy_signature = signature

        self.store.event(
            None,
            "info",
            "proxy_reload",
            f"Pool actualizado: {len(self.workers)} proxies únicos en {len(files)} archivos",
        )
        return True

    def _is_blocked(self, index: int) -> bool:
        endpoint = self._endpoint_id(self.workers[index - 1])
        health = self.store.proxy_health(endpoint)
        return bool(health and health.get("blocked"))

    def _paths(self, index: int) -> tuple[Path, Path]:
        return self.control_dir / f"worker-{index}.pause", self.control_dir / f"worker-{index}.drain"

    def _validate_index(self, index: int) -> None:
        if index not in self.runtime:
            raise ValueError(f"Worker inexistente: {index}")

    def _desired(self, index: int) -> str:
        return self.store.worker_rows()[index]["desired_state"]

    def _set_desired(self, index: int, state: str) -> None:
        self.store.update_worker(index, desired_state=state)

    def _manager_loop(self) -> None:
        while not self.closing:
            try:
                if time.time() >= self._proxy_next_refresh:
                    self._proxy_next_refresh = time.time() + 2
                    self._reload_proxy_pool()
                if time.time() >= self.blocked_retest_next and not self._retest_running:
                    self.blocked_retest_next = time.time() + 120
                    threading.Thread(target=self._auto_retest_blocked, daemon=True, name="blocked-retest").start()
                self._collect_proxy_inventory()
                self._schedule_proxy_inventory()
                if not self.engine_running:
                    time.sleep(1)
                    continue
                self._refresh_queue_stats()
                self._rebalance_random_pool()
                rows = self.store.worker_rows()
                for item in list(self.workers):
                    index = item["index"]
                    row = rows[index]
                    with self.lock:
                        running = index in self.processes
                        next_restart = self.runtime[index]["next_restart"]
                        manual_restart = self.runtime[index]["restart_pending"]
                    if (
                        row["desired_state"] == "running"
                        and (bool(row["auto_restart"]) or manual_restart)
                        and not running
                        and time.time() >= next_restart
                    ):
                        self.start(index, persist=False, restarted=bool(next_restart))
            except Exception as exc:
                self.store.event(None, "error", "manager", f"Error del supervisor: {exc}")
            time.sleep(1)

    def _rebalance_random_pool(self) -> None:
        if not self.engine_running:
            return
        target = max(0, min(int(self.store.setting("target_workers", "0")), len(self.workers)))
        rows = self.store.worker_rows()
        indexes = [item["index"] for item in self.workers]
        selected = [index for index in indexes if rows[index]["desired_state"] in {"running", "paused"}]
        missing = target - len(selected)
        if missing <= 0:
            return
        candidates = [
            index for index in indexes
            if rows[index]["desired_state"] == "stopped" and index not in self.processes
            and not self._is_blocked(index)
        ]
        ready = [index for index in candidates if self.runtime[index].get("rotation_ready_at", 0) <= time.time()]
        selection_pool = ready if len(ready) >= missing else candidates
        for index in secrets.SystemRandom().sample(selection_pool, min(missing, len(selection_pool))):
            self.resume(index)

    def _refresh_queue_stats(self) -> None:
        now = time.time()
        if now < self.queue_next_refresh:
            return
        self.queue_next_refresh = now + 5
        code, body = self.tts_api_call("stats", {})
        if code != 200:
            return
        try:
            data = json.loads(body.decode("utf-8"))
        except (UnicodeDecodeError, json.JSONDecodeError):
            return
        if not data.get("ok") or not isinstance(data.get("queue"), dict):
            return
        queue = data["queue"]
        with self.lock:
            self.queue_stats = {
                "total": int(queue.get("total", 0)),
                "pending": int(queue.get("pending", 0)),
                "claimed": int(queue.get("claimed", 0)),
                "done": int(queue.get("done", 0)),
                "failed": int(queue.get("failed", 0)),
                "remaining": int(queue.get("remaining", 0)),
                "available": True,
                "updated_at": utc_now(),
            }

    def start(self, index: int, persist: bool = True, restarted: bool = False) -> None:
        self._validate_index(index)
        with self.lock:
            if index in self.processes:
                return
            pause_file, drain_file = self._paths(index)
            pause_file.unlink(missing_ok=True)
            drain_file.unlink(missing_ok=True)
            if persist:
                self._set_desired(index, "running")
            env = dict(os.environ)
            env.update({
                "PYTHONIOENCODING": "utf-8",
                "PYTHONUNBUFFERED": "1",
                "TTS_WORKER_TAKE": "1",
                "TTS_WORKER_MAX_JOBS": "1",
                "TTS_WORKER_PAUSE_FILE": str(pause_file),
                "TTS_WORKER_DRAIN_FILE": str(drain_file),
                "TTS_WORKER_NAME": self.workers[index - 1]["name"],
                "TTS_PROXY_HOST": self.workers[index - 1]["host"],
                "TTS_PROXY_PORT": self.workers[index - 1]["port"],
                "TTS_PROXY_USERNAME": self.workers[index - 1]["username"],
                "TTS_PROXY_PASSWORD": self.workers[index - 1]["password"],
            })
            kwargs = {
                "cwd": str(self.root), "env": env,
                "stdin": subprocess.DEVNULL, "stdout": subprocess.PIPE,
                "stderr": subprocess.PIPE, "text": True, "encoding": "utf-8",
                "errors": "replace", "bufsize": 1,
            }
            if os.name == "nt":
                kwargs["creationflags"] = subprocess.CREATE_NO_WINDOW
            try:
                process = subprocess.Popen(
                    [sys.executable, str(self.worker_script), str(index)], **kwargs
                )
            except Exception as exc:
                self.runtime[index].update(status="error", last_log=str(exc), next_restart=time.time() + 10)
                self.store.update_worker(index, last_error=str(exc))
                self.store.event(index, "error", "start", f"No se pudo iniciar: {exc}")
                return
            self.processes[index] = process
            self.runtime[index].update(
                status="starting", pid=process.pid, current_job=None,
                last_activity=utc_now(), started_at=utc_now(), next_restart=0.0,
                restart_pending=False,
            )
            if restarted:
                self.store.increment(index, "restarts")
            self.store.event(index, "info", "start", f"Worker iniciado, PID {process.pid}")
            threading.Thread(target=self._read_stream, args=(index, process, process.stdout, "stdout"), daemon=True).start()
            threading.Thread(target=self._read_stream, args=(index, process, process.stderr, "stderr"), daemon=True).start()
            threading.Thread(target=self._wait_process, args=(index, process), daemon=True).start()

    def _read_stream(self, index: int, process: subprocess.Popen, stream, stream_name: str) -> None:
        if stream is None:
            return
        for raw in stream:
            line = raw.rstrip("\r\n")
            if line:
                self._handle_line(index, process, line, stream_name)

    def _handle_line(self, index: int, process: subprocess.Popen, line: str, stream_name: str) -> None:
        line = re.sub(r"([a-zA-Z][a-zA-Z0-9+.-]*://)[^@\s]+@", r"\1***@", line)
        with self.lock:
            if self.processes.get(index) is not process:
                return
            state = self.runtime[index]
            state["last_activity"] = utc_now()
            state["last_log"] = line
            level, kind, job_hash = ("error" if stream_name == "stderr" else "info"), "log", None
            match = GENERATING_RE.search(line)
            if match:
                job_hash = match.group(1)
                state.update(status="busy", current_job=job_hash)
                kind = "job_started"
            elif (match := OK_RE.search(line)):
                job_hash = match.group(1)
                state.update(status="idle", current_job=None)
                self.store.increment(index, "jobs_ok")
                kind = "job_ok"
                self.store.update_proxy_health(
                    self._endpoint_id(self.workers[index - 1]), consecutive_failures=0
                )
            elif (match := FAIL_RE.search(line)):
                job_hash = match.group(1)
                error = match.group(2)
                state.update(status="idle", current_job=None)
                self.store.increment(index, "jobs_failed")
                self.store.update_worker(index, last_error=error)
                level, kind = "error", "job_failed"
                endpoint = self._endpoint_id(self.workers[index - 1])
                health = self.store.proxy_health(endpoint) or {}
                streak = int(health.get("consecutive_failures") or 0) + 1
                health_updates = {"consecutive_failures": streak}
                if streak >= PROXY_BLOCK_THRESHOLD and not health.get("blocked"):
                    health_updates.update(blocked=1, blocked_at=utc_now(), blocked_reason=error)
                    self.store.event(
                        index, "error", "proxy_blocked",
                        f"Proxy {endpoint} bloqueado tras {streak} fallos seguidos: {error}",
                    )
                self.store.update_proxy_health(endpoint, **health_updates)
            elif (match := IDENTITY_RE.match(line)):
                try:
                    identity = json.loads(match.group(1))
                except json.JSONDecodeError:
                    identity = {}
                state.update(
                    public_ip=str(identity.get("ip") or "").strip() or None,
                    country_code=str(identity.get("country_code") or "").strip().upper() or None,
                    country_name=str(identity.get("country") or "").strip() or None,
                    city=str(identity.get("city") or "").strip() or None,
                    latitude=identity.get("latitude"),
                    longitude=identity.get("longitude"),
                    location_status="located" if self._valid_coordinates(identity) else "partial",
                    location_retry_at=0.0 if self._valid_coordinates(identity) else time.time() + 300,
                )
                self.store.update_proxy_location(
                    self._endpoint_id(self.workers[index - 1]),
                    public_ip=state["public_ip"], country_code=state["country_code"],
                    country_name=state["country_name"], city=state["city"],
                    latitude=float(identity["latitude"]) if self._valid_coordinates(identity) else None,
                    longitude=float(identity["longitude"]) if self._valid_coordinates(identity) else None,
                    status=state["location_status"], last_error=None,
                )
                kind = "identity"
            elif "IP publica" in line:
                state["public_ip"] = line.split(":", 1)[-1].strip()
            elif "Pais" in line:
                state["country_code"] = line.split(":", 1)[-1].strip()
            elif "HEARTBEAT" in line:
                state["status"] = "idle"
                kind = "heartbeat"
            elif line == "CONTROL PAUSED":
                state["status"] = "paused"
                kind = "paused"
            elif line == "CONTROL RESUMED":
                state["status"] = "idle"
                kind = "resumed"
            elif line == "CONTROL DRAINED":
                state["status"] = "draining"
                kind = "drained"
            elif line == "CONTROL ROTATE":
                self._set_desired(index, "stopped")
                state["status"] = "draining"
                state["rotation_ready_at"] = time.time() + 30
                kind = "rotated"
            elif "pull fallo HTTP" in line or "pull rechazo API" in line:
                state["status"] = "degraded"
                level, kind = "warning", "api_error"
            elif state["status"] == "starting" and "====" in line:
                state["status"] = "idle"
            safe_name = re.sub(r"[^a-zA-Z0-9_-]+", "-", self.workers[index - 1]["name"]).strip("-").lower()
            log_path = self.logs_dir / f"{safe_name}.log"
            with log_path.open("a", encoding="utf-8") as log:
                log.write(f"[{utc_now()}] {line}\n")
            if kind != "heartbeat":
                self.store.event(index, level, kind, line, job_hash)

    def _wait_process(self, index: int, process: subprocess.Popen) -> None:
        code = process.wait()
        if process.stdout:
            process.stdout.close()
        if process.stderr:
            process.stderr.close()
        with self.lock:
            if self.processes.get(index) is not process:
                return
            self.processes.pop(index, None)
            state = self.runtime[index]
            row = self.store.worker_rows()[index]
            desired = row["desired_state"]
            unexpected = desired == "running" and not self.closing
            should_restart = unexpected and (bool(row["auto_restart"]) or state["restart_pending"])
            state.update(
                pid=None, current_job=None,
                status="restarting" if should_restart else ("error" if unexpected else "stopped"),
                next_restart=time.time() + 3 if should_restart else 0.0,
            )
            level = "warning" if should_restart else "info"
            self.store.event(index, level, "exit", f"Worker terminó con código {code}")

    def _terminate(self, index: int) -> None:
        with self.lock:
            process = self.processes.get(index)
        if not process or process.poll() is not None:
            return
        if os.name == "nt":
            subprocess.run(
                ["taskkill", "/PID", str(process.pid), "/T", "/F"],
                stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL,
                creationflags=subprocess.CREATE_NO_WINDOW,
            )
        else:
            process.terminate()

    def stop(self, index: int) -> None:
        self._validate_index(index)
        self._set_desired(index, "stopped")
        with self.lock:
            running = index in self.processes
            self.runtime[index]["status"] = "stopping" if running else "stopped"
        self.store.event(index, "warning", "command", "Detención inmediata solicitada por un operador")
        self._terminate(index)

    def drain(self, index: int) -> None:
        self._validate_index(index)
        self._set_desired(index, "stopped")
        _pause, drain_file = self._paths(index)
        drain_file.touch()
        with self.lock:
            if index in self.processes:
                self.runtime[index]["status"] = "draining"
            else:
                self.runtime[index]["status"] = "stopped"
        self.store.event(index, "info", "command", "Drenado solicitado: terminará el trabajo actual")

    def pause(self, index: int) -> None:
        self._validate_index(index)
        self._set_desired(index, "paused")
        pause_file, _drain = self._paths(index)
        pause_file.touch()
        with self.lock:
            if index in self.processes:
                self.runtime[index]["status"] = "pausing"
        self.store.event(index, "info", "command", "Pausa solicitada por un operador")

    def resume(self, index: int) -> None:
        self._validate_index(index)
        pause_file, drain_file = self._paths(index)
        pause_file.unlink(missing_ok=True)
        drain_file.unlink(missing_ok=True)
        self._set_desired(index, "running")
        self.store.event(index, "info", "command", "Reanudación solicitada por un operador")
        with self.lock:
            running = index in self.processes
        if not running:
            self.start(index, persist=False)

    def restart(self, index: int) -> None:
        self._validate_index(index)
        self._set_desired(index, "running")
        with self.lock:
            running = index in self.processes
            self.runtime[index]["status"] = "restarting"
            self.runtime[index]["next_restart"] = time.time() + 1
        self.store.event(index, "warning", "command", "Reinicio solicitado por un operador")
        if running:
            self._terminate(index)
        else:
            self.start(index, persist=False, restarted=True)

    def rotate_proxy(self, index: int) -> None:
        self._validate_index(index)
        with self.lock:
            active_indexes = {
                item["index"] for item in self.workers
                if item["index"] != index
                and self.store.worker_rows()[item["index"]]["desired_state"] in {"running", "paused"}
            }
            candidates = [
                item for item in self.workers
                if item["index"] not in active_indexes and item["index"] != index
                and not self._is_blocked(item["index"])
            ]
            if not candidates:
                raise ValueError("No hay un proxy libre (sin contar bloqueados); drena o detén otro worker primero")
            replacement = secrets.choice(candidates)
            current = self.workers[index - 1]
            old_host, new_host = current["host"], replacement["host"]
            for key in ("host", "port", "username", "password"):
                current[key], replacement[key] = replacement[key], current[key]
            running = index in self.processes
            self.runtime[index].update(
                status="restarting", public_ip=None, country_code=None,
                country_name=None, city=None, latitude=None, longitude=None,
                location_status="pending", location_retry_at=0.0,
                next_restart=time.time() + 1, restart_pending=True,
            )
            replacement_index = replacement["index"]
            self.runtime[replacement_index].update(
                public_ip=None, country_code=None, country_name=None, city=None,
                latitude=None, longitude=None, location_status="pending", location_retry_at=0.0,
            )
            self._hydrate_cached_location(current)
            self._hydrate_cached_location(replacement)
        self._set_desired(index, "running")
        self.store.event(index, "warning", "proxy_rotated", f"Proxy reemplazado: {old_host} -> {new_host}")
        if running:
            self._terminate(index)
        else:
            self.start(index, persist=False, restarted=True)

    def set_auto_restart(self, index: int, enabled: bool) -> None:
        self._validate_index(index)
        self.store.update_worker(index, auto_restart=1 if enabled else 0)
        self.store.event(index, "info", "policy", f"Reinicio automático: {'activo' if enabled else 'inactivo'}")

    def apply_target(self, target: int) -> None:
        target = max(0, min(target, len(self.workers)))
        self.store.reset_counters()
        self.store.set_setting("target_workers", str(target))
        if not self.engine_running:
            for item in self.workers:
                self._set_desired(item["index"], "stopped")
            self.store.event(None, "info", "fleet", f"Concurrencia preparada: {target}; motor detenido")
            return
        rows = self.store.worker_rows()
        with self.lock:
            busy = [index for index, state in self.runtime.items() if state["status"] == "busy"]
        retained = set(busy[:target])
        available = [index for index in rows if index not in retained and not self._is_blocked(index)]
        retained.update(secrets.SystemRandom().sample(available, min(max(0, target - len(retained)), len(available))))
        for item in self.workers:
            index = item["index"]
            if index in retained:
                self.resume(index)
            else:
                self.drain(index)
        self.store.event(None, "info", "fleet", f"Contadores reiniciados y pool aleatorio aplicado: {target}")

    def set_tts_api_url(self, url: str) -> None:
        url = url.strip()
        if url and not re.match(r"^https?://", url):
            raise ValueError("URL invalida: debe empezar con http:// o https://")
        # 1. Efecto inmediato en este proceso: pc_tts_worker.BASE_URL se lee
        #    una vez al importar el modulo (os.getenv), por eso hay que
        #    pisarlo a mano -- afecta tanto a self.tts_api_call (stats, usado
        #    por este mismo proceso) como a los workers nuevos que se
        #    levanten de ahora en adelante (subprocess.Popen hereda os.environ).
        self._tts_worker_module.BASE_URL = url
        os.environ["TTS_API_URL"] = url
        # 2. Persistencia en settings (sqlite): sobrevive a un reinicio del
        #    orquestador aunque el .env todavia no se haya tocado.
        self.store.set_setting("tts_api_url", url)
        # 3. Persistencia en el .env de systemd, si se indico la ruta (ver
        #    TTS_ENV_FILE en installer/farm.sh): para que un reinicio normal
        #    del servicio (EnvironmentFile) tambien arranque ya con el valor
        #    nuevo, sin depender de que esta sesion de settings siga viva.
        env_file = os.getenv("TTS_ENV_FILE", "").strip()
        if env_file:
            path = Path(env_file)
            lines = []
            found = False
            if path.exists():
                for line in path.read_text(encoding="utf-8").splitlines():
                    if line.startswith("TTS_API_URL="):
                        lines.append(f"TTS_API_URL={url}")
                        found = True
                    else:
                        lines.append(line)
            if not found:
                lines.append(f"TTS_API_URL={url}")
            path.write_text("\n".join(lines) + "\n", encoding="utf-8")
        self.store.event(None, "info", "fleet", f"TTS_API_URL actualizado: {url or '(vacio)'}")

    def start_engine(self) -> None:
        self._reload_proxy_pool(force=True)
        if self.proxy_error:
            raise ValueError(self.proxy_error)
        if not self.workers:
            raise ValueError(f"No hay proxies en {self.proxy_dir}")
        if self.engine_running:
            return
        self.engine_running = True
        self.queue_next_refresh = 0.0
        target = max(0, min(int(self.store.setting("target_workers", "0")), len(self.workers)))
        if target == 0:
            target = min(25, len(self.workers))
            self.store.set_setting("target_workers", str(target))
        self.apply_target(target)
        self.store.event(None, "info", "engine", f"Motor iniciado con objetivo {target}")

    def stop_all(self) -> None:
        self.engine_running = False
        self._proxy_signature = None
        self.store.set_setting("target_workers", "0")
        for item in self.workers:
            self.stop(item["index"])
        with self.lock:
            self.queue_stats.update(available=False, updated_at=None)
        self.store.event(None, "warning", "fleet", "Detención inmediata de toda la flota")

    def blocked_proxies_view(self) -> list[dict]:
        """Lista para el panel 'Proxies con problemas': un proxy bloqueado sigue
        apareciendo aunque su worker este detenido/excluido de la rotacion."""
        rows = self.store.blocked_proxies()
        by_endpoint = {self._endpoint_id(item): item for item in self.workers}
        result = []
        for row in rows:
            endpoint = row["endpoint"]
            worker = by_endpoint.get(endpoint)
            location = self.store.proxy_location(endpoint) or {}
            result.append({
                "endpoint": endpoint,
                "index": worker["index"] if worker else None,
                "name": worker["name"] if worker else None,
                "consecutive_failures": row["consecutive_failures"],
                "blocked_at": row["blocked_at"],
                "blocked_reason": row["blocked_reason"],
                "last_test_at": row["last_test_at"],
                "last_test_ok": bool(row["last_test_ok"]) if row["last_test_ok"] is not None else None,
                "last_test_error": row["last_test_error"],
                "country_code": location.get("country_code"),
                "country_name": location.get("country_name"),
                "city": location.get("city"),
            })
        return result

    def _test_proxy_isolated(self, item: dict, timeout: int) -> tuple[bool, str | None]:
        """Corre la prueba de conectividad en un PROCESO APARTE (`python
        pc_tts_worker_proxy.py --test-connectivity`), nunca dentro de un hilo
        del orquestador. CRITICO (2026-09-06): la unica forma de darle un
        proxy a gTTS es via variables de entorno globales del proceso; si eso
        corriera aca (proceso compartido, de larga vida, que ademas es quien
        arranca workers nuevos heredando su propio os.environ), una prueba
        puede dejar el proxy pegado y romper la conexion normal (sin proxy)
        de CUALQUIER worker que arranque mientras tanto -- eso tumbo la flota
        entera la primera vez ("pull fallo ... ProxyError"). Un subproceso
        aparte tiene su PROPIO entorno; lo que le pase ahi no toca al resto."""
        env = dict(os.environ)
        env.update({
            "PYTHONIOENCODING": "utf-8",
            "TTS_PROXY_HOST": item["host"], "TTS_PROXY_PORT": item["port"],
            "TTS_PROXY_USERNAME": item["username"], "TTS_PROXY_PASSWORD": item["password"],
        })
        try:
            result = subprocess.run(
                [sys.executable, str(self.worker_script), "--test-connectivity"],
                cwd=str(self.root), env=env, capture_output=True, text=True, timeout=timeout,
            )
        except subprocess.TimeoutExpired:
            return False, f"sin respuesta en {timeout}s (proxy probablemente colgado)"
        if result.returncode == 0:
            return True, None
        salida = (result.stderr or result.stdout or "error desconocido").strip()
        ultima_linea = salida.splitlines()[-1] if salida else "error desconocido"
        return False, re.sub(r"^FAIL:\s*", "", ultima_linea)[:300]

    def test_proxy(self, endpoint: str) -> dict:
        """Prueba manual de un proxy bloqueado (boton 'Probar'): si conecta bien
        ahora, se reactiva de inmediato (vuelve a la bolsa de la rotacion)."""
        endpoint = (endpoint or "").strip().lower()
        if not endpoint:
            raise ValueError("Falta indicar que proxy probar")
        item = next((candidate for candidate in self.workers if self._endpoint_id(candidate) == endpoint), None)
        if not item:
            raise ValueError("Ese proxy ya no esta en el pool actual (revisa los archivos de cuentas)")
        now = utc_now()
        ok, error = self._test_proxy_isolated(item, timeout=25)
        if not ok:
            self.store.update_proxy_health(endpoint, last_test_at=now, last_test_ok=0, last_test_error=error)
            self.store.event(None, "warning", "proxy_test", f"Proxy {endpoint} sigue fallando: {error}")
            return {"ok": False, "reactivated": False, "error": error}
        self.store.update_proxy_health(
            endpoint, blocked=0, consecutive_failures=0,
            last_test_at=now, last_test_ok=1, last_test_error=None,
        )
        self.store.event(None, "info", "proxy_test", f"Proxy {endpoint} paso la prueba manual: reactivado")
        return {"ok": True, "reactivated": True}

    def _auto_retest_blocked(self) -> None:
        """Reintento automatico cada 2 minutos (2026-09-06, a pedido del
        usuario): sin esto, un proxy con un fallo pasajero quedaba fuera de
        la rotacion hasta que alguien entrara al panel a probarlo a mano."""
        self._retest_running = True
        try:
            self.test_all_blocked(origen="automatico (cada 2 min)")
        except Exception as exc:
            self.store.event(None, "error", "proxy_test_all_auto", f"Fallo el reintento automatico: {exc}")
        finally:
            self._retest_running = False

    def test_all_blocked(self, origen: str = "manual") -> dict:
        """Boton 'Reintentar todos' (2026-09-06): prueba TODOS los proxies
        bloqueados en paralelo (un subproceso aislado por cada uno, ver
        _test_proxy_isolated), para separar de un tiron los falsos positivos
        (un pico de carga o de Google que ya paso) de los que de verdad
        siguen muertos. Tambien se dispara solo cada 2 minutos (ver
        _auto_retest_blocked); `origen` solo cambia el texto del evento."""
        blocked = self.store.blocked_proxies()
        if not blocked:
            return {"ok": True, "total": 0, "reactivated": 0, "still_failing": 0, "details": []}

        def _probar(endpoint):
            item = next((candidate for candidate in self.workers if self._endpoint_id(candidate) == endpoint), None)
            if not item:
                return False, "Ese proxy ya no está en el pool actual"
            return self._test_proxy_isolated(item, timeout=30)

        endpoints = [row["endpoint"] for row in blocked]
        futures = {endpoint: self.inventory_executor.submit(_probar, endpoint) for endpoint in endpoints}
        now = utc_now()
        reactivated = still_failing = 0
        details = []
        for endpoint, future in futures.items():
            try:
                ok, error = future.result(timeout=45)
            except Exception as exc:
                ok, error = False, f"sin respuesta ({exc})"
            if ok:
                self.store.update_proxy_health(endpoint, blocked=0, consecutive_failures=0, last_test_at=now, last_test_ok=1, last_test_error=None)
                reactivated += 1
            else:
                self.store.update_proxy_health(endpoint, last_test_at=now, last_test_ok=0, last_test_error=error)
                still_failing += 1
            details.append({"endpoint": endpoint, "ok": ok, "error": error})
        self.store.event(
            None, "info", "proxy_test_all",
            f"Reintento {origen}: {reactivated} reactivados, {still_failing} siguen fallando (de {len(blocked)})",
        )
        return {"ok": True, "total": len(blocked), "reactivated": reactivated, "still_failing": still_failing, "details": details}

    def edit_proxy(self, old_endpoint: str, host: str, port: str, username: str, password: str) -> dict:
        """Botón 'Editar' de un proxy con problemas (2026-09-06): reemplaza sus
        credenciales por un proxy nuevo, sobrescribiendo la linea exacta en el
        archivo de cuentas de donde salió (no crea una linea aparte). Actualiza
        el worker en memoria en el mismo slot (mismo patron que rotate_proxy),
        asi que no deja un slot fantasma cuando el archivo se recarga solo."""
        old_endpoint = (old_endpoint or "").strip().lower()
        host = (host or "").strip()
        port = (port or "").strip()
        username = (username or "").strip()
        password = (password or "").strip()
        if not old_endpoint or not host or not port or not username or not password:
            raise ValueError("Host, puerto, usuario y contraseña son obligatorios")
        try:
            numeric_port = int(port)
        except ValueError:
            raise ValueError("Puerto inválido") from None
        if not 1 <= numeric_port <= 65535:
            raise ValueError("Puerto fuera de rango")
        new_endpoint = f"{host.casefold()}:{numeric_port}"
        with self.lock:
            item = next((candidate for candidate in self.workers if self._endpoint_id(candidate) == old_endpoint), None)
            if not item:
                raise ValueError("Ese proxy ya no está en el pool actual")
            if new_endpoint != old_endpoint and any(self._endpoint_id(w) == new_endpoint for w in self.workers):
                raise ValueError("Ya existe otro proxy con ese host y puerto")
            source_file = item.get("source")
            if not source_file:
                raise ValueError("No se pudo determinar el archivo de origen de este proxy")
            file_path = self.proxy_dir / source_file
            target_host, target_port = item["host"].casefold(), str(item["port"])
            lines = file_path.read_text(encoding="utf-8-sig").splitlines()
            new_line = f"{host}:{numeric_port}:{username}:{password}"
            replaced = False
            for i, raw in enumerate(lines):
                stripped = raw.strip()
                if not stripped or stripped.startswith("#"):
                    continue
                parts = [p.strip() for p in stripped.split(":", 3)]
                if len(parts) == 4 and parts[0].casefold() == target_host and parts[1] == target_port:
                    lines[i] = new_line
                    replaced = True
                    break
            if not replaced:
                raise ValueError("No se encontró la línea de este proxy en el archivo (¿se editó por fuera?)")
            file_path.write_text("\n".join(lines) + "\n", encoding="utf-8")
            item.update(host=host, port=str(numeric_port), username=username, password=password)
            self.runtime[item["index"]].update(
                public_ip=None, country_code=None, country_name=None, city=None,
                latitude=None, longitude=None, location_status="pending", location_retry_at=0.0,
            )
            worker_name_value = item["name"]
        self.store.delete_proxy_health(old_endpoint)
        self.store.event(
            None, "warning", "proxy_edited",
            f"Proxy editado ({worker_name_value}): {old_endpoint} -> {new_endpoint}",
        )
        return {"ok": True, "endpoint": new_endpoint}

    def snapshot(self) -> dict:
        rows = self.store.worker_rows()
        result = []
        with self.lock:
            for item in self.workers:
                index = item["index"]
                runtime = dict(self.runtime[index])
                row = rows[index]
                result.append({
                    "index": item["index"],
                    "name": item["name"],
                    "host": item["host"],
                    "port": item["port"],
                    **runtime,
                    "desired_state": row["desired_state"],
                    "auto_restart": bool(row["auto_restart"]),
                    "jobs_ok": row["jobs_ok"],
                    "jobs_failed": row["jobs_failed"],
                    "restarts": row["restarts"],
                    "last_error": row["last_error"],
                    # Proxy bloqueado tras fallos seguidos (2026-09-06): el frontend lo
                    # pinta en rojo como "blocked" sin importar el status de ciclo de
                    # vida (idle/stopped/draining) que tenga el worker en ese instante.
                    "blocked": self._is_blocked(index),
                })
        active = sum(1 for item in result if item["status"] in ACTIVE_STATES)
        return {
            "online": True,
            "version": "3.0.0",
            "engine_running": self.engine_running,
            "target_workers": int(self.store.setting("target_workers", "0")),
            "tts_api_url": self.store.setting("tts_api_url", os.getenv("TTS_API_URL", "").strip()),
            "workers": result,
            "proxy_pool": {
                "directory": str(self.proxy_dir),
                "files": list(self.proxy_files),
                "duplicates_ignored": self.proxy_duplicates,
                "error": self.proxy_error,
                "inventory": {
                    "located": sum(1 for item in result if item.get("location_status") == "located"),
                    "probing": sum(1 for item in result if item.get("location_status") == "probing"),
                    "partial": sum(1 for item in result if item.get("location_status") == "partial"),
                    "failed": sum(1 for item in result if item.get("location_status") == "error"),
                },
            },
            "queue": dict(self.queue_stats),
            "summary": {
                "total": len(result), "active": active,
                "busy": sum(1 for item in result if item["status"] == "busy"),
                "paused": sum(1 for item in result if item["status"] in {"paused", "pausing"}),
                "jobs_ok": sum(item["jobs_ok"] for item in result),
                "jobs_failed": sum(item["jobs_failed"] for item in result),
                "blocked": sum(1 for item in result if item["blocked"]),
            },
            "blocked_proxies": self.blocked_proxies_view(),
            "events": self.store.events(180),
        }

    def shutdown(self) -> None:
        if self.closed:
            return
        self.closing = True
        self.engine_running = False
        for future in self.inventory_futures.values():
            future.cancel()
        self.inventory_executor.shutdown(wait=False, cancel_futures=True)
        for item in self.workers:
            self._set_desired(item["index"], "stopped")
            self._terminate(item["index"])
        deadline = time.time() + 5
        while self.processes and time.time() < deadline:
            time.sleep(0.05)
        self.manager.join(timeout=2)
        self.store.event(None, "info", "orchestrator", "Control plane detenido")
        self.store.close()
        self.closed = True


def load_or_create_token(data_dir: Path) -> str:
    path = data_dir / "control.token"
    data_dir.mkdir(parents=True, exist_ok=True)
    if path.is_file():
        return path.read_text(encoding="utf-8").strip()
    token = secrets.token_urlsafe(40)
    path.write_text(token, encoding="utf-8")
    return token


def make_handler(orchestrator: Orchestrator, token: str):
    class Handler(BaseHTTPRequestHandler):
        server_version = "SynervoxControlPlane/2.0"

        def log_message(self, _format, *_args):
            return

        def _authorized(self) -> bool:
            supplied = self.headers.get("X-Control-Token", "")
            return bool(supplied) and hmac.compare_digest(supplied, token)

        def _json(self, status: int, payload: dict) -> None:
            body = json.dumps(payload, ensure_ascii=False).encode("utf-8")
            self.send_response(status)
            self.send_header("Content-Type", "application/json; charset=utf-8")
            self.send_header("Content-Length", str(len(body)))
            self.send_header("Cache-Control", "no-store")
            self.end_headers()
            self.wfile.write(body)

        def _body(self) -> dict:
            length = int(self.headers.get("Content-Length", "0"))
            if length > 65536:
                raise ValueError("Solicitud demasiado grande")
            return json.loads(self.rfile.read(length) or b"{}")

        def do_GET(self):
            if not self._authorized():
                self._json(401, {"error": "No autorizado"})
                return
            parsed = urlparse(self.path)
            if parsed.path == "/api/health":
                self._json(200, {"online": True, "version": "3.0.0"})
            elif parsed.path == "/api/snapshot":
                self._json(200, orchestrator.snapshot())
            elif parsed.path == "/api/events":
                query = parse_qs(parsed.query)
                worker = int(query["worker"][0]) if query.get("worker") else None
                self._json(200, {"events": orchestrator.store.events(500, worker)})
            else:
                self._json(404, {"error": "Ruta inexistente"})

        def do_POST(self):
            if not self._authorized():
                self._json(401, {"error": "No autorizado"})
                return
            try:
                payload = self._body()
                path = urlparse(self.path).path
                match = re.fullmatch(r"/api/workers/(\d+)/(start|stop|pause|resume|drain|restart|rotate-proxy|auto-restart)", path)
                if match:
                    index, action = int(match.group(1)), match.group(2)
                    if action in {"start", "resume", "restart", "rotate-proxy"} and not orchestrator.engine_running:
                        raise ValueError("El motor está detenido; usa Iniciar motor")
                    if action == "auto-restart":
                        orchestrator.set_auto_restart(index, bool(payload.get("enabled")))
                    else:
                        method = action.replace("-", "_")
                        getattr(orchestrator, method)(index)
                    self._json(200, {"ok": True})
                    return
                if path == "/api/proxy-health/test":
                    self._json(200, orchestrator.test_proxy(str(payload.get("endpoint", ""))))
                    return
                if path == "/api/proxy-health/test-all":
                    self._json(200, orchestrator.test_all_blocked())
                    return
                if path == "/api/proxy-health/edit":
                    self._json(200, orchestrator.edit_proxy(
                        str(payload.get("endpoint", "")), str(payload.get("host", "")),
                        str(payload.get("port", "")), str(payload.get("username", "")),
                        str(payload.get("password", "")),
                    ))
                    return
                if path == "/api/fleet/tts-api-url":
                    orchestrator.set_tts_api_url(str(payload.get("url", "")))
                    self._json(200, {"ok": True})
                    return
                if path == "/api/fleet/target":
                    orchestrator.apply_target(int(payload["target"]))
                elif path == "/api/fleet/start-engine":
                    orchestrator.start_engine()
                elif path == "/api/fleet/stop-all":
                    orchestrator.stop_all()
                else:
                    self._json(404, {"error": "Ruta inexistente"})
                    return
                self._json(200, {"ok": True})
            except (ValueError, KeyError, json.JSONDecodeError) as exc:
                self._json(400, {"error": str(exc)})
            except Exception as exc:
                orchestrator.store.event(None, "error", "api", str(exc))
                self._json(500, {"error": str(exc)})

    return Handler


def main() -> int:
    parser = argparse.ArgumentParser()
    default_root = Path(__file__).resolve().parent.parent
    parser.add_argument("--root", type=Path, default=default_root)
    parser.add_argument("--data-dir", type=Path)
    parser.add_argument("--host", default="127.0.0.1")
    parser.add_argument("--port", type=int, default=8766)
    parser.add_argument("--check", action="store_true")
    args = parser.parse_args()
    root = args.root.resolve()
    data_dir = (args.data_dir or root / "orchestrator-data").resolve()

    proxies, proxy_files, duplicates = read_proxy_directory(root / "proxy-accounts")
    if not (root / "pc_tts_worker_proxy.py").is_file():
        raise SystemExit(f"Falta {root / 'pc_tts_worker_proxy.py'}")
    if args.check:
        print(json.dumps({
            "ok": True,
            "workers": len(proxies),
            "proxy_files": proxy_files,
            "duplicates_ignored": duplicates,
            "root": str(root),
        }, ensure_ascii=False))
        return 0

    token = load_or_create_token(data_dir)
    orchestrator = Orchestrator(root, data_dir)
    server = ThreadingHTTPServer((args.host, args.port), make_handler(orchestrator, token))
    server.daemon_threads = True
    (data_dir / "orchestrator.pid").write_text(str(os.getpid()), encoding="ascii")
    try:
        server.serve_forever(poll_interval=0.5)
    except KeyboardInterrupt:
        pass
    finally:
        server.server_close()
        orchestrator.shutdown()
        (data_dir / "orchestrator.pid").unlink(missing_ok=True)
    return 0


if __name__ == "__main__":
    raise SystemExit(main())
