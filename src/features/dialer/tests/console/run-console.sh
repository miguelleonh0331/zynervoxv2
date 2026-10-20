#!/bin/bash
# Comprueba la consola del agente (app/web/modules/agc) sin navegador ni base
# de datos: levanta el marcado real de crm.php en un mini-DOM y ejecuta el
# crm.js real contra un discador simulado.
#
# No toca MariaDB, Asterisk ni el despliegue. Se puede correr en cualquier
# momento, también con el motor en marcha.
set -euo pipefail
here=$(cd -- "$(dirname -- "${BASH_SOURCE[0]}")" && pwd)
command -v node >/dev/null || { echo 'Falta node para ejecutar esta prueba.' >&2; exit 1; }
node "$here/console.js" || exit 1

# La presentación de app/web/demo se verifica aparte: no tiene servidor ni
# base, así que sin esto no habría forma de saber si el guion corre entero.
echo
node "$here/demo-check.js" || exit 1

# Y la maqueta de supervisión de app/web/demo-sup, por lo mismo.
echo
node "$here/sup-check.js"
