#!/bin/bash
# La pantalla del supervisor reusa sup.js de la maqueta, pero alimentada con
# datos del servidor: muchos conjuntos llegan vacios porque el motor todavia
# no los calcula. Esta prueba ejecuta sup.js bajo Node con un DOM minimo y
# recorre arranque, las once vistas, el Modo TV, el buscador y una accion,
# primero con TODO vacio y despues con una campana real sin agentes.
# Sin esto, un acceso sin guarda deja la pagina en blanco en vez del cuadro.
set -euo pipefail
here=$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)
command -v node >/dev/null || { echo 'Node es requisito de esta prueba.'; exit 1; }
for caso in vacio parcial; do
    salida=$(cd "$here/supervisor" && timeout 60 node run.js "$here/supervisor/$caso.js" 2>&1 || true)
    echo "$salida" | grep -q '^OK' || { echo "FALLA con datos '$caso':"; echo "$salida"; exit 1; }
    echo "PASS ($caso): $salida"
done
