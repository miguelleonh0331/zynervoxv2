# Base aislada

MariaDB 10.11 se ejecuta en Docker sin reemplazar MySQL/MariaDB del host. El puerto
se enlaza exclusivamente a `127.0.0.1`; `installer/database.sh init` elige el primer
puerto libre desde `3307`.

`init/001-schema.sql` fue generado desde Kamatera con `mariadb-dump --no-data`.
Contiene 364 tablas y ninguna fila, contraseña, contacto o dato operativo. El gestor
crea además `zynervox_install_meta`, un grupo `ADMIN` y un administrador cuya
contraseña aleatoria queda únicamente en `database/.env`.

```bash
./installer/database.sh init
./installer/database.sh status
./installer/database.sh credentials
./installer/database.sh install-config
./installer/database.sh backup /ruta/backup.sql.gz
./installer/database.sh restore /ruta/backup.sql.gz
./installer/database.sh down
```

El volumen `zynervox_mariadb_data` persiste después de `down`. Nunca agregar
`database/.env`, `database/runtime` ni backups al repositorio.
