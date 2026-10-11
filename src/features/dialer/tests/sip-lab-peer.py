#!/usr/bin/env python3
"""Loopback-only SIP/RTP fixtures. No external number or network destination."""
import hashlib
import json
import re
import select
import socket
import struct
import sys
import time
import uuid

with open(sys.argv[1], encoding='utf-8') as source:
    credentials = json.load(source)
agent_user = credentials['extension']
secret = credentials['secret']
agent = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
agent.bind(('127.0.0.1', 5096))
client = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
client.bind(('127.0.0.1', 5097))
media = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
media.bind(('127.0.0.1', 13996))
dialogs = {}
register_id = str(uuid.uuid4())

def send_register(auth=None):
    cseq = 2 if auth else 1
    auth_line = f'Authorization: {auth}\r\n' if auth else ''
    message = (f'REGISTER sip:127.0.0.1 SIP/2.0\r\nVia: SIP/2.0/UDP 127.0.0.1:5096;branch=z9hG4bKreg{cseq};rport\r\n'
               f'From: <sip:{agent_user}@127.0.0.1>;tag=labregister\r\nTo: <sip:{agent_user}@127.0.0.1>\r\n'
               f'Call-ID: {register_id}\r\nCSeq: {cseq} REGISTER\r\nContact: <sip:{agent_user}@127.0.0.1:5096>\r\n'
               f'Max-Forwards: 70\r\nExpires: 300\r\n{auth_line}Content-Length: 0\r\n\r\n')
    agent.sendto(message.encode(), ('127.0.0.1', 5060))

def parse(data):
    lines = data.decode(errors='replace').split('\r\n')
    headers = {}
    for line in lines[1:]:
        if ':' in line:
            key, value = line.split(':', 1)
            headers.setdefault(key.lower(), value.strip())
    return lines[0], headers, data.decode(errors='replace').split('\r\n\r\n', 1)[-1]

def response(sock, remote, headers, status, body=''):
    tag = '' if ';tag=' in headers['to'] else ';tag=labfixture'
    contact = '5096' if sock is agent else '5097'
    packet = (f'SIP/2.0 {status}\r\nVia: {headers["via"]}\r\nFrom: {headers["from"]}\r\n'
              f'To: {headers["to"]}{tag}\r\nCall-ID: {headers["call-id"]}\r\nCSeq: {headers["cseq"]}\r\n'
              f'Contact: <sip:fixture@127.0.0.1:{contact}>\r\n'
              + ('Content-Type: application/sdp\r\n' if body else '')
              + f'Content-Length: {len(body.encode())}\r\n\r\n{body}')
    sock.sendto(packet.encode(), remote)

def hangup(dialog):
    h = dialog['headers']
    target = h.get('contact', '<sip:asterisk@127.0.0.1:5060>')
    target = re.search(r'<([^>]+)>', target).group(1)
    packet = (f'BYE {target} SIP/2.0\r\nVia: SIP/2.0/UDP 127.0.0.1:{dialog["port"]};branch=z9hG4bK{uuid.uuid4().hex}\r\n'
              f'From: {h["to"]};tag=labfixture\r\nTo: {h["from"]}\r\nCall-ID: {h["call-id"]}\r\n'
              'CSeq: 2 BYE\r\nMax-Forwards: 70\r\nContent-Length: 0\r\n\r\n')
    dialog['socket'].sendto(packet.encode(), dialog['remote'])
    dialog['done'] = True

send_register()
deadline = time.monotonic() + 180
while time.monotonic() < deadline:
    ready, _, _ = select.select([agent, client, media], [], [], .01)
    now = time.monotonic()
    for sock in ready:
        data, remote = sock.recvfrom(65535)
        if sock is media:
            continue
        first, h, body = parse(data)
        if first.startswith('SIP/2.0 401') and h.get('call-id') == register_id:
            auth = dict(re.findall(r'(\w+)="([^"]*)"', h['www-authenticate']))
            md5 = lambda value: hashlib.md5(value.encode()).hexdigest()
            digest = md5(md5(f'{agent_user}:{auth["realm"]}:{secret}') + ':' + auth['nonce'] + ':' + md5('REGISTER:sip:127.0.0.1'))
            send_register(f'Digest username="{agent_user}", realm="{auth["realm"]}", nonce="{auth["nonce"]}", uri="sip:127.0.0.1", response="{digest}", algorithm=MD5')
        elif first.startswith('SIP/2.0 200') and h.get('call-id') == register_id:
            print('READY: loopback SIP agent registered', flush=True)
        elif first.startswith('OPTIONS ') or first.startswith('BYE '):
            response(sock, remote, h, '200 OK')
            if first.startswith('BYE ') and h['call-id'] in dialogs:
                dialogs[h['call-id']]['done'] = True
        elif first.startswith('CANCEL '):
            response(sock, remote, h, '200 OK')
            dialog = dialogs.get(h['call-id'])
            if dialog:
                response(sock, remote, dialog['headers'], '487 Request Terminated')
                dialog['done'] = True
        elif first.startswith('INVITE '):
            print('INVITE: ' + first + ' received at ' + str(sock.getsockname()), flush=True)
            if h['call-id'] in dialogs:
                continue
            mode = first.split()[1].split('@')[0].split(':')[-1]
            match = re.search(r'm=audio (\d+)', body)
            ip = re.search(r'c=IN IP4 ([\d.]+)', body)
            # Asterisk may advertise the server's public address; all media stays local.
            rtp = ('127.0.0.1', int(match.group(1))) if match else None
            response(sock, remote, h, '180 Ringing')
            dialogs[h['call-id']] = {'headers': h, 'remote': remote, 'socket': sock,
                'port': 5096 if sock is agent else 5097, 'mode': mode, 'rtp': rtp,
                'answer_at': now + (.1 if sock is agent else 2), 'answered': False,
                'next_rtp': now, 'seq': 0, 'stamp': 0, 'ssrc': int(uuid.uuid4().hex[:8],16), 'done': False}
    for dialog in dialogs.values():
        if dialog['done'] or dialog['mode'] == '000003':
            continue
        if not dialog['answered'] and now >= dialog['answer_at']:
            sdp = 'v=0\r\no=fixture 1 1 IN IP4 127.0.0.1\r\ns=Dialer lab\r\nc=IN IP4 127.0.0.1\r\nt=0 0\r\nm=audio 13996 RTP/AVP 0\r\na=rtpmap:0 PCMU/8000\r\na=sendrecv\r\n'
            response(dialog['socket'], dialog['remote'], dialog['headers'], '200 OK', sdp)
            dialog['answered'] = True
            dialog['start'] = now
        if not dialog['answered']:
            continue
        age = now - dialog['start']
        if age >= (8 if dialog['socket'] is agent else (.7 if dialog['mode'] == '000005' else 30)):
            hangup(dialog)
            continue
        if dialog['rtp'] and now >= dialog['next_rtp']:
            voice = dialog['socket'] is agent or dialog['mode'] == '000002' or .5 <= age <= .8
            payload = (bytes([0x10])*10 + bytes([0x90])*10)*8 if voice else bytes([0xff])*160
            packet = struct.pack('!BBHII', 0x80, 0, dialog['seq'] % 65536, dialog['stamp'] % 2**32, dialog['ssrc']) + payload
            media.sendto(packet, dialog['rtp'])
            dialog['seq'] += 1
            dialog['stamp'] += 160
            dialog['next_rtp'] = now + .02
