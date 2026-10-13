"""Controlled localhost SIP/RTP peer for preanswer verification; never calls a carrier."""
import argparse
import audioop
import random
import re
import socket
import struct
import threading
import time
import wave

parser=argparse.ArgumentParser();parser.add_argument('--audio',required=True);parser.add_argument('--answer',action='store_true');args=parser.parse_args()
with wave.open(args.audio,'rb') as wav:
    assert (wav.getnchannels(),wav.getsampwidth(),wav.getframerate())==(1,2,8000)
    pcm=wav.readframes(wav.getnframes())
sip=socket.socket(socket.AF_INET,socket.SOCK_DGRAM);sip.bind(('127.0.0.1',5088));sip.settimeout(.2)
rtp=socket.socket(socket.AF_INET,socket.SOCK_DGRAM);rtp.bind(('127.0.0.1',7088))
stop=threading.Event();invite=None;peer=None;thread=None
sdp='v=0\r\no=qa 1 1 IN IP4 127.0.0.1\r\ns=ZV2 preanswer QA\r\nc=IN IP4 127.0.0.1\r\nt=0 0\r\nm=audio 7088 RTP/AVP 0\r\na=rtpmap:0 PCMU/8000\r\na=sendonly\r\n'
def response(message,address,code,reason,body=''):
    lines=[]
    for line in message.split('\r\n')[1:]:
        if line.lower().startswith(('via:','from:','to:','call-id:','cseq:')):
            if line.lower().startswith('to:') and ';tag=' not in line:line+=';tag=zv2qa'
            lines.append(line)
    payload='SIP/2.0 '+str(code)+' '+reason+'\r\n'+'\r\n'.join(lines)+'\r\nContact: <sip:qa@127.0.0.1:5088>\r\n'
    if body:payload+='Content-Type: application/sdp\r\n'
    payload+='Content-Length: '+str(len(body.encode()))+'\r\n\r\n'+body
    sip.sendto(payload.encode(),address)
def stream(port):
    seq=random.randrange(65536);stamp=random.randrange(2**32);ssrc=random.randrange(2**32);offset=0;started=time.monotonic()
    while not stop.is_set() and time.monotonic()-started<18:
        block=pcm[offset:offset+320];offset+=320
        if len(block)<320:offset=0;block=(block+b'\x00'*320)[:320]
        packet=struct.pack('!BBHII',0x80,0,seq,stamp,ssrc)+audioop.lin2ulaw(block,2)
        rtp.sendto(packet,('127.0.0.1',port));seq=(seq+1)%65536;stamp=(stamp+160)%2**32
        stop.wait(.02)
    print('RTP_STREAM_FINISHED',flush=True)
deadline=time.monotonic()+30
try:
    print('SIP_QA_READY',flush=True)
    while time.monotonic()<deadline and not stop.is_set():
        try:raw,address=sip.recvfrom(65536)
        except socket.timeout:continue
        message=raw.decode(errors='replace');method=message.split(' ',1)[0]
        if method=='INVITE':
            if invite is not None:continue
            invite=message;peer=address;port=int(re.search(r'm=audio\s+(\d+)',message).group(1))
            response(message,address,100,'Trying')
            response(message,address,200 if args.answer else 183,'OK' if args.answer else 'Session Progress',sdp)
            thread=threading.Thread(target=stream,args=(port,));thread.start()
            print('ANSWER_SENT' if args.answer else 'EARLY_MEDIA_183_SENT',flush=True)
        elif method=='CANCEL':
            response(message,address,200,'OK');response(invite,peer,487,'Request Terminated');print('CANCEL_RECEIVED',flush=True);stop.set()
        elif method=='BYE':response(message,address,200,'OK');print('BYE_RECEIVED',flush=True);stop.set()
finally:
    stop.set()
    if thread:thread.join(timeout=2)
    sip.close();rtp.close()
