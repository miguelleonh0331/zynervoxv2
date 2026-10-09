#!/usr/bin/env bash
set -euo pipefail
source_directory="${1:?Falta carpeta fuente}"
destination_directory="${2:?Falta carpeta destino}"
command -v rsync >/dev/null 2>&1 || { echo "Falta rsync: instalar con apt-get install rsync o zypper install rsync" >&2; exit 1; }
[[ -d "$source_directory" ]] || { echo "Fuente web inexistente" >&2; exit 1; }
source_directory="$(realpath "$source_directory")"
destination_directory="$(realpath -m "$destination_directory")"
[[ "$destination_directory" != / && "$destination_directory" != "$source_directory" && "$destination_directory" != "$source_directory/"* && "$source_directory" != "$destination_directory/"* ]] || { echo "Destino web inseguro o solapado con fuente" >&2; exit 1; }
printf '[%s] Revisando web y transfiriendo solo archivos nuevos/modificados\n' "$(date +%T)"
install -d -m 0750 "$destination_directory"
rsync -rt --itemize-changes --stats --exclude='runtime/' --exclude='secrets/' --exclude='/config/deployment.php' --exclude='/config/reporting_mirror.json' -- "$source_directory/" "$destination_directory/"
