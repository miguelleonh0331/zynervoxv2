#!/usr/bin/env python3
"""Ten loopback SIP agents and a concurrent RTP destination; synthetic data only."""
import audioop
import hashlib
import json
import math
import os
import re
import select
import signal
import socket
import struct
import sys
import time
import uuid

directory = sys.argv[1]
with open(directory + '/credentials.json') as source:
    credentials = json.load(source)
peers, dialogs, media = {}, {}, {}
running = True
next_evidence = 0
for index, account in enumerate(credentials):
    sock = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
    sock.bind(('127.0.0.1', 0))
    peers[sock] = dict(account, port=sock.getsockname()[1], register_id=str(uuid.uuid4()), frequency=880 + index * 40)
client = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
client.bind(('127.0.0.1', 5097))

def stop(*args):
    global running
    running = False
signal.signal(signal.SIGTERM, stop)
signal.signal(signal.SIGINT, stop)

def register(sock, auth=None):
    peer = peers[sock]
    seq = 2 if auth else 1
    packet = (f'REGISTER sip:127.0.0.1 SIP/2.0\r\nVia: SIP/2.0/UDP 127.0.0.1:{peer["port"]};branch=z9hG4bKreg{seq};rport\r\n'
              f'From: <sip:{peer["extension"]}@127.0.0.1>;tag=labregister\r\nTo: <sip:{peer["extension"]}@127.0.0.1>\r\n'
              f'Call-ID: {peer["register_id"]}\r\nCSeq: {seq} REGISTER\r\nContact: <sip:{peer["extension"]}@127.0.0.1:{peer["port"]}>\r\n'
              'Max-Forwards: 70\r\nExpires: 600\r\n' + (f'Authorization: {auth}\r\n' if auth else '') + 'Content-Length: 0\r\n\r\n')
    sock.sendto(packet.encode(), ('127.0.0.1', 5060))

def parse(data):
    text = data.decode(errors='replace')
    head, _, body = text.partition('\r\n\r\n')
    lines = head.split('\r\n')
    headers = {}
    for line in lines[1:]:
        if ':' in line:
            key, value = line.split(':', 1)
            headers.setdefault(key.lower(), value.strip())
    return lines[0], headers, body

def respond(sock, remote, h, status, body=''):
    port = sock.getsockname()[1]
    tag = '' if ';tag=' in h['to'] else ';tag=labfixture'
    packet = (f'SIP/2.0 {status}\r\nVia: {h["via"]}\r\nFrom: {h["from"]}\r\nTo: {h["to"]}{tag}\r\nCall-ID: {h["call-id"]}\r\nCSeq: {h["cseq"]}\r\nContact: <sip:fixture@127.0.0.1:{port}>\r\n'
              + ('Content-Type: application/sdp\r\n' if body else '') + f'Content-Length: {len(body.encode())}\r\n\r\n{body}')
    sock.sendto(packet.encode(), remote)

def bye(d):
    h = d['headers']
    target = re.search(r'<([^>]+)>', h.get('contact', '<sip:asterisk@127.0.0.1:5060>')).group(1)
    packet = (f'BYE {target} SIP/2.0\r\nVia: SIP/2.0/UDP 127.0.0.1:{d["socket"].getsockname()[1]};branch=z9hG4bK{uuid.uuid4().hex}\r\n'
              f'From: {h["to"]};tag=labfixture\r\nTo: {h["from"]}\r\nCall-ID: {h["call-id"]}\r\nCSeq: 2 BYE\r\nMax-Forwards: 70\r\nContent-Length: 0\r\n\r\n')
    d['socket'].sendto(packet.encode(), d['remote'])
    d['done'] = True

for sock in peers:
    register(sock)
deadline = time.monotonic() + 600
while running and time.monotonic() < deadline:
    ready, _, _ = select.select(list(peers) + [client] + list(media), [], [], .005)
    now = time.monotonic()
    for sock in ready:
        data, remote = sock.recvfrom(65535)
        if sock in media:
            d = media[sock]
            if len(data) >= 172:
                d['rx_packets'] += 1
                decoded = audioop.ulaw2lin(data[12:], 2)
                if audioop.rms(decoded, 2) > 200:
                    d['rx_voice_packets'] += 1
            continue
        first, h, body = parse(data)
        if first.startswith('SIP/2.0 401') and sock in peers:
            peer = peers[sock]
            auth = dict(re.findall(r'(\w+)="([^"]*)"', h['www-authenticate']))
            md5 = lambda s: hashlib.md5(s.encode()).hexdigest()
            digest = md5(md5(f'{peer["extension"]}:{auth["realm"]}:{peer["secret"]}') + ':' + auth['nonce'] + ':' + md5('REGISTER:sip:127.0.0.1'))
            register(sock, f'Digest username="{peer["extension"]}", realm="{auth["realm"]}", nonce="{auth["nonce"]}", uri="sip:127.0.0.1", response="{digest}", algorithm=MD5')
        elif first.startswith('SIP/2.0 200') and sock in peers and h.get('call-id') == peers[sock]['register_id']:
            print('READY ' + peers[sock]['extension'], flush=True)
        elif first.startswith(('OPTIONS ', 'BYE ', 'CANCEL ')):
            respond(sock, remote, h, '200 OK')
            if h.get('call-id') in dialogs and not first.startswith('OPTIONS '):
                d = dialogs[h['call-id']]
                if first.startswith('CANCEL '):
                    respond(sock, remote, d['headers'], '487 Request Terminated')
                d['done'] = True
        elif first.startswith('INVITE ') and h['call-id'] in dialogs:
            d=dialogs[h['call-id']]
            match=re.search(r'm=audio (\d+)',body)
            if match: d['target']=('127.0.0.1',int(match.group(1)))
            port=d['media'].getsockname()[1]
            sdp=f'v=0\r\no=fixture 1 2 IN IP4 127.0.0.1\r\ns=Dialer lab\r\nc=IN IP4 127.0.0.1\r\nt=0 0\r\nm=audio {port} RTP/AVP 0\r\na=rtpmap:0 PCMU/8000\r\na=sendrecv\r\n'
            respond(sock,remote,h,'200 OK',sdp)
        elif first.startswith('INVITE ') and h['call-id'] not in dialogs:
            mode = first.split()[1].split('@')[0].split(':')[-1]
            match = re.search(r'm=audio (\d+)', body)
            rtp = socket.socket(socket.AF_INET, socket.SOCK_DGRAM)
            rtp.bind(('127.0.0.1', 0))
            d = {'headers': h, 'remote': remote, 'socket': sock, 'mode': mode, 'media': rtp,
                 'target': ('127.0.0.1', int(match.group(1))) if match else None,
                 'agent': peers[sock]['extension'] if sock in peers else None,
                 'answer_at': now + (.1 if sock in peers else 1), 'answered': False,
                 'next_rtp': now, 'seq': 0, 'stamp': 0, 'ssrc': int(uuid.uuid4().hex[:8], 16),
                 'done': False, 'rx_packets': 0, 'rx_voice_packets': 0}
            dialogs[h['call-id']] = d
            media[rtp] = d
            respond(sock, remote, h, '180 Ringing')
    for d in list(dialogs.values()):
        if d['done'] or d['mode'].endswith('03'):
            continue
        if not d['answered'] and now >= d['answer_at']:
            port = d['media'].getsockname()[1]
            sdp = f'v=0\r\no=fixture 1 1 IN IP4 127.0.0.1\r\ns=Dialer lab\r\nc=IN IP4 127.0.0.1\r\nt=0 0\r\nm=audio {port} RTP/AVP 0\r\na=rtpmap:0 PCMU/8000\r\na=sendrecv\r\n'
            respond(d['socket'], d['remote'], d['headers'], '200 OK', sdp)
            d.update(answered=True, start=now)
        if not d['answered']:
            continue
        age = now - d['start']
        if age >= (40 if d['agent'] else (.7 if d['mode'].endswith('05') else 90)):
            bye(d)
            continue
        if d['target'] and now >= d['next_rtp']:
            voice = bool(d['agent']) or d['mode'].endswith('02') or .5 <= age <= .8 or age >= 4
            frequency = peers[d['socket']]['frequency'] if d['agent'] else 440
            samples = [int(6000 * math.sin(2 * math.pi * frequency * (d['stamp'] + i) / 8000)) if voice else 0 for i in range(160)]
            payload = audioop.lin2ulaw(struct.pack('<160h', *samples), 2)
            packet = struct.pack('!BBHII', 0x80, 0, d['seq'] % 65536, d['stamp'] % 2**32, d['ssrc']) + payload
            d['media'].sendto(packet, d['target'])
            d['seq'] += 1
            d['stamp'] += 160
            d['next_rtp'] = now + .02
    if dialogs and now >= next_evidence:
        evidence = [{'agent': d['agent'], 'number': d['mode'], 'answered': d['answered'], 'done': d['done'], 'tx_packets': d['seq'], 'rx_packets': d['rx_packets'], 'rx_voice_packets': d['rx_voice_packets']} for d in dialogs.values()]
        with open(directory + '/rtp.json.tmp', 'w') as target:
            json.dump(evidence, target)
        os.replace(directory + '/rtp.json.tmp', directory + '/rtp.json')
        next_evidence = now + .25
