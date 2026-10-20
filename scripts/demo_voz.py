#!/usr/bin/env python3
"""Genera la narración hablada de la presentación (app/web/demo).

Los audios se crean UNA SOLA VEZ y quedan como archivos estáticos: la
página nunca llama a la API. Eso la deja funcionando sin internet y evita
gastar créditos cada vez que alguien la mira.

La llave vive en /root/elebencpia.txt y no sale de ahí: no se imprime, no
se guarda en el repositorio y no viaja a la página. Este script es el único
que la lee.

Cada frase se guarda junto a la huella de su texto. Si mañana se corrige
una coma, se regenera esa frase y nada más: el cupo de la cuenta es de
10 000 caracteres y la narración completa son 5 500.

Uso:
    python3 scripts/demo_voz.py muestras        # 3 voces, una frase corta
    python3 scripts/demo_voz.py generar         # la narración completa
    python3 scripts/demo_voz.py generar --forzar
    python3 scripts/demo_voz.py costo           # cuánto costaría, sin llamar
    python3 scripts/demo_voz.py verificar       # qué frases quedaron sin audio
"""

import hashlib
import json
import os
import re
import sys
import urllib.request

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
DEMO = os.path.join(RAIZ, 'app', 'web', 'demo')
AUDIO = os.path.join(DEMO, 'audio')
INDICE = os.path.join(AUDIO, 'indice.json')
LLAVE = '/root/elebencpia.txt'

# Elegida para esta presentación: voz institucional cálida y de confianza,
# acento latinoamericano neutro. El público son gerentes, no consumidores:
# pesa más la credibilidad que la energía publicitaria.
#
# Ojo: la biblioteca compartida de ElevenLabs distingue las voces que un
# plan gratuito puede usar de las que exigen plan Creator. Las tres de abajo
# están permitidas en el plan de esta cuenta; otras más conocidas no.
# LÍMITE DEL PLAN: con cuenta gratuita la API rechaza TODA voz de la
# biblioteca compartida —incluidas las latinoamericanas— con
# "Free users cannot use library voices via the API". Sólo responden las
# voces propias de la cuenta, que son de locutores en inglés; el modelo
# multilingüe las hace hablar español, con un dejo extranjero leve.
#
# Con plan Creator se desbloquean las latinas de verdad. Cambiar entonces
# VOZ por una de VOCES_LATINAS y volver a correr `generar --forzar`: son
# dos minutos y el resto no se toca.
VOZ = 'XrExE9yKIg1WjnnlVkGX'          # Matilda — profesional, cálida, pausada
VOZ_NOMBRE = 'Matilda (voz de cuenta, español multilingüe)'

CANDIDATAS = [
    ('matilda', 'XrExE9yKIg1WjnnlVkGX', 'profesional y pausada'),
    ('sarah',   'EXAVITQu4vr4xnSDxMaL', 'serena y cercana'),
    ('bella',   'hpp4J3VqNfWAUOO0d1Us', 'cálida y luminosa'),
]

# Para cuando la cuenta pase a Creator. Verificadas como disponibles en la
# biblioteca; hoy la API las rechaza por plan, no por existencia.
VOCES_LATINAS = [
    ('daniela', 'NUEuVCKSYxEA5YrK3kmx', 'latinoamericana neutra, institucional'),
    ('lily',    'ek0qR5Bu0N3aPdijsdae', 'peruana, cálida y profesional'),
    ('johanna', 'c338PHkXJ2cW8fdMPhzP', 'latinoamericana, serena, dicción impecable'),
]

MODELO = 'eleven_multilingual_v2'
AJUSTES = {
    # Estabilidad alta: una narración corporativa no debe variar de tono
    # entre frases. Similarity alta mantiene el timbre parejo en 45 cortes.
    'stability': 0.55,
    'similarity_boost': 0.80,
    'style': 0.20,
    'use_speaker_boost': True,
}

FRASE_MUESTRA = ('Al entrar ya está todo: la cartera del día, el expediente '
                 'del cliente y lo que pasa en la llamada, en una sola pantalla.')


def llave():
    with open(LLAVE, encoding='utf-8') as f:
        k = f.read().strip()
    if not k:
        sys.exit('La llave está vacía en ' + LLAVE)
    return k


def frases():
    """Saca los textos de narración de demo.js, en orden de aparición."""
    js = open(os.path.join(DEMO, 'demo.js'), encoding='utf-8').read()
    # Primer argumento de cada narrar('...'), admitiendo comillas escapadas.
    crudas = re.findall(r"narrar\(\s*'((?:[^'\\]|\\.)*)'", js)
    out = []
    for i, t in enumerate(crudas, 1):
        texto = t.replace("\\'", "'").replace('\\\\', '\\')
        out.append({'id': 'n%02d' % i, 'texto': texto})
    return out


def nombre_archivo(texto):
    """Mismo cálculo que hashTexto() en demo.js.

    El nombre sale del propio texto para que no haya una lista de
    correspondencias que mantener: si la frase cambia, cambia el archivo.
    """
    h = 0
    for ch in texto:
        h = (h * 31 + ord(ch)) & 0xFFFFFFFF
    # base36, igual que Number.prototype.toString(36)
    if h == 0:
        return 'v0'
    d, out = '0123456789abcdefghijklmnopqrstuvwxyz', ''
    while h:
        out = d[h % 36] + out
        h //= 36
    return 'v' + out


def huella(texto):
    return hashlib.sha256((VOZ + '|' + texto).encode('utf-8')).hexdigest()[:16]


def hablar(k, texto, voz, destino):
    cuerpo = json.dumps({
        'text': texto, 'model_id': MODELO, 'voice_settings': AJUSTES
    }).encode('utf-8')
    pedido = urllib.request.Request(
        'https://api.elevenlabs.io/v1/text-to-speech/' + voz,
        data=cuerpo,
        headers={'xi-api-key': k, 'Content-Type': 'application/json',
                 'Accept': 'audio/mpeg'})
    with urllib.request.urlopen(pedido, timeout=120) as r:
        datos = r.read()
    if len(datos) < 1000:
        raise RuntimeError('respuesta demasiado corta (%d bytes)' % len(datos))
    with open(destino, 'wb') as f:
        f.write(datos)
    return len(datos)


def cupo(k):
    pedido = urllib.request.Request(
        'https://api.elevenlabs.io/v1/user/subscription',
        headers={'xi-api-key': k})
    with urllib.request.urlopen(pedido, timeout=30) as r:
        d = json.load(r)
    return d.get('character_count', 0), d.get('character_limit', 0)


def cmd_costo():
    fs = frases()
    total = sum(len(f['texto']) for f in fs)
    print('frases      : %d' % len(fs))
    print('caracteres  : %d' % total)
    try:
        usados, limite = cupo(llave())
        print('cupo        : %d usados de %d · quedan %d' % (usados, limite, limite - usados))
        print('tras generar: quedarían %d' % (limite - usados - total))
    except Exception as e:
        print('cupo        : no se pudo consultar (%s)' % e)


def cmd_muestras():
    os.makedirs(AUDIO, exist_ok=True)
    k = llave()
    print('frase de prueba: %d caracteres por voz' % len(FRASE_MUESTRA))
    for nombre, voz, desc in CANDIDATAS:
        destino = os.path.join(AUDIO, 'muestra-%s.mp3' % nombre)
        try:
            n = hablar(k, FRASE_MUESTRA, voz, destino)
            print('  ok    %-10s %-44s %6d bytes' % (nombre, desc, n))
        except Exception as e:
            print('  FALLO %-10s %s' % (nombre, e))
    print('\nEscúchelas en demo/audio/muestra-*.mp3')


def cmd_generar(forzar=False):
    os.makedirs(AUDIO, exist_ok=True)
    indice = {}
    if os.path.exists(INDICE) and not forzar:
        indice = json.load(open(INDICE, encoding='utf-8'))

    fs = frases()
    for f in fs:
        f['archivo'] = nombre_archivo(f['texto']) + '.mp3'
    pendientes = [f for f in fs
                  if forzar or indice.get(f['id'], {}).get('huella') != huella(f['texto'])
                  or not os.path.exists(os.path.join(AUDIO, f['archivo']))]
    gasto = sum(len(f['texto']) for f in pendientes)

    k = llave()
    usados, limite = cupo(k)
    print('a generar   : %d de %d frases · %d caracteres' % (len(pendientes), len(fs), gasto))
    print('cupo        : quedan %d' % (limite - usados))
    if gasto > (limite - usados):
        sys.exit('NO ALCANZA el cupo. Faltan %d caracteres.' % (gasto - (limite - usados)))
    if not pendientes:
        print('nada que hacer: todas al día.')
        return

    nuevo = dict(indice)
    for i, f in enumerate(pendientes, 1):
        destino = os.path.join(AUDIO, f['archivo'])
        try:
            n = hablar(k, f['texto'], VOZ, destino)
            nuevo[f['id']] = {'huella': huella(f['texto']), 'archivo': f['archivo'],
                              'bytes': n, 'chars': len(f['texto']), 'voz': VOZ}
            print('  %2d/%2d  %s  %5d bytes  %s…' % (i, len(pendientes), f['id'], n,
                                                     f['texto'][:52]))
        except Exception as e:
            print('  %2d/%2d  %s  FALLO: %s' % (i, len(pendientes), f['id'], e))
    # El índice guarda la voz y la huella, nunca la llave.
    nuevo['_voz'] = {'id': VOZ, 'nombre': VOZ_NOMBRE, 'modelo': MODELO}
    with open(INDICE, 'w', encoding='utf-8') as fh:
        json.dump(nuevo, fh, ensure_ascii=False, indent=1)
    print('\níndice: %s' % INDICE)


def cmd_verificar():
    """Comprueba que cada frase tenga su audio con el nombre que pide la página."""
    fs = frases()
    faltan, pesan = [], 0
    for f in fs:
        ruta = os.path.join(AUDIO, nombre_archivo(f['texto']) + '.mp3')
        if os.path.exists(ruta):
            pesan += os.path.getsize(ruta)
        else:
            faltan.append((f['id'], nombre_archivo(f['texto']), f['texto'][:46]))
    print('frases     : %d' % len(fs))
    print('con audio  : %d' % (len(fs) - len(faltan)))
    print('peso total : %.1f MB' % (pesan / 1048576.0))
    for i, n, t in faltan:
        print('  FALTA %s  %s  %s…' % (i, n, t))
    return 1 if faltan else 0


if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else 'costo'
    if cmd == 'costo':
        cmd_costo()
    elif cmd == 'muestras':
        cmd_muestras()
    elif cmd == 'verificar':
        sys.exit(cmd_verificar())
    elif cmd == 'generar':
        cmd_generar('--forzar' in sys.argv)
    else:
        sys.exit(__doc__)
