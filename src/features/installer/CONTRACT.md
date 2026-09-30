# Contrato: installer

Instala primero la interfaz web de forma repetible sobre Ubuntu. La ausencia de
Asterisk, MySQL o VICIdial produce una instalación parcial utilizable para inspección,
sin cancelar el despliegue de archivos. `check.sh --strict` exige todas las
integraciones, `--dry-run` no escribe y las migraciones requieren autorización
explícita. `--skip-packages` evita cambios al sistema. No incorpora ni sobrescribe
secretos, datos, audios o grabaciones.

El gestor `installer/database.sh` crea MariaDB 10.11 en Docker, importa únicamente
el esquema versionado, genera credenciales locales y mantiene los datos en un volumen.
