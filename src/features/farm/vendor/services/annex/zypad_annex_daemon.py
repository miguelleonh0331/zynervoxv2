#!/usr/bin/env python3
"""Daemon root, escucha en 127.0.0.1, ejecuta /usr/local/bin/zypad_annex.
Existe porque Apache (apache2.service) tiene RestrictSUIDSGID=yes -- bloquea
que www-data use sudo (setuid) para escalar a root, asi que en vez de eso
PHP le pega por HTTP a este daemon local, que ya corre como root via systemd
(sin necesidad de setuid). 2026-08-18.
"""
import json
import os
import subprocess
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

BIND = ("127.0.0.1", int(os.getenv("ZYPAD_ANNEX_PORT", "8811")))
SCRIPT = os.getenv("ZYPAD_ANNEX_SCRIPT", "/usr/local/bin/zypad_annex")
PYTHON = os.getenv("ZYPAD_PYTHON", "/usr/bin/python3")


class Handler(BaseHTTPRequestHandler):
    def log_message(self, fmt, *args):
        pass

    def do_POST(self):
        length = int(self.headers.get("Content-Length", 0))
        raw = self.rfile.read(length) if length else b"{}"
        try:
            data = json.loads(raw.decode("utf-8"))
        except Exception:
            self._reply(400, {"ok": False, "error": "JSON invalido"})
            return

        action = str(data.get("action", ""))
        agent = str(data.get("agent", ""))
        password = str(data.get("password", ""))
        args = [PYTHON, SCRIPT, action]

        if action in ("status", "status_detail", "get_destino"):
            pass
        elif action in ("stop_all", "start_all"):
            pass
        elif action == "set_destino":
            args.append(str(data.get("host", "")))
        elif action == "create_range":
            args += [str(data.get("from", "")), str(data.get("to", ""))]
            if password:
                args.append(password)
        elif action in ("create", "delete", "start", "stop"):
            args.append(agent)
            if action == "create":
                args.append(password or agent)
        else:
            self._reply(400, {"ok": False, "error": "Accion invalida"})
            return

        try:
            proc = subprocess.run(args, text=True, stdout=subprocess.PIPE, stderr=subprocess.STDOUT, timeout=30)
            out = proc.stdout.strip()
            try:
                parsed = json.loads(out)
            except Exception:
                parsed = {"ok": False, "error": out or "sin respuesta"}
            if action == "set_destino" and parsed.get("ok"):
                # El daemon es un proceso de larga vida que cargo
                # ZYPAD_ASTERISK_HOST una sola vez al arrancar (EnvironmentFile).
                # Sin esto, los subprocesos siguientes (create/get_destino)
                # heredarian el valor viejo hasta reiniciar el daemon, aunque
                # el .env ya haya sido actualizado en disco.
                os.environ["ZYPAD_ASTERISK_HOST"] = str(parsed.get("host", ""))
            self._reply(200, parsed)
        except Exception as exc:
            self._reply(500, {"ok": False, "error": str(exc)})

    def _reply(self, code, payload):
        body = json.dumps(payload).encode("utf-8")
        self.send_response(code)
        self.send_header("Content-Type", "application/json")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


if __name__ == "__main__":
    # 2026-09-07: HTTPServer (un solo hilo) hacia que una consulta de status
    # lenta bloqueara todas las demas. ThreadingHTTPServer atiende
    # varias solicitudes a la vez; daemon_threads=True evita threads huerfanos.
    server = ThreadingHTTPServer(BIND, Handler)
    server.daemon_threads = True
    server.serve_forever()
