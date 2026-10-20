#!/usr/bin/env python3
"""Narración hablada de la maqueta de supervisión (app/web/demo-sup).

Reutiliza todo el motor de demo_voz.py —la llave, el modelo, la voz, el
índice con huellas— y sólo cambia de dónde salen los textos y dónde van
los audios. Las reglas son las mismas y no se repiten acá:

  · la llave vive en /root/elebencpia.txt y no sale de ahí
  · los audios se crean UNA vez y quedan como archivos estáticos
  · si una frase cambia, se regenera esa frase y nada más

Uso:
    python3 scripts/demo_voz_sup.py costo
    python3 scripts/demo_voz_sup.py generar [--forzar]
    python3 scripts/demo_voz_sup.py verificar
"""

import os
import re
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import demo_voz as dv                                    # noqa: E402

RAIZ = os.path.dirname(os.path.dirname(os.path.abspath(__file__)))
dv.DEMO = os.path.join(RAIZ, 'app', 'web', 'demo-sup')
dv.AUDIO = os.path.join(dv.DEMO, 'audio')
dv.INDICE = os.path.join(dv.AUDIO, 'indice.json')


def frases():
    """Los textos de narrar() en guion.js, en orden de aparición."""
    js = open(os.path.join(dv.DEMO, 'guion.js'), encoding='utf-8').read()
    crudas = re.findall(r"narrar\(\s*'((?:[^'\\]|\\.)*)'", js)
    return [{'id': 'n%02d' % i, 'texto': t.replace("\\'", "'").replace('\\\\', '\\')}
            for i, t in enumerate(crudas, 1)]


dv.frases = frases

if __name__ == '__main__':
    cmd = sys.argv[1] if len(sys.argv) > 1 else 'costo'
    if cmd == 'costo':
        dv.cmd_costo()
    elif cmd == 'verificar':
        sys.exit(dv.cmd_verificar())
    elif cmd == 'generar':
        dv.cmd_generar('--forzar' in sys.argv)
    else:
        sys.exit(__doc__)
