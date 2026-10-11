#!/usr/bin/env python3
import json
import os
import socket
import sys

event = 'VPN_CONNECT' if os.environ.get('script_type') == 'client-connect' else 'VPN_DISCONNECT'
payload = {'event': event, 'username': os.environ.get('common_name', ''),
           'ip': os.environ.get('trusted_ip', '')}
try:
    with socket.socket(socket.AF_UNIX) as connection:
        connection.settimeout(5)
        connection.connect('/run/zv-vpn-events.sock')
        connection.sendall(json.dumps(payload).encode() + b'\n')
        result = connection.recv(64)
    sys.exit(0 if result == b'OK\n' else 1)
except Exception:
    sys.exit(1)
