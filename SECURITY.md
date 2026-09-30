# Seguridad

Nunca confirmar `astguiclient.conf`, archivos `.env`, tokens, contraseñas, claves,
grabaciones ni exportaciones SQL. `reporting_mirror.json` se genera localmente a
partir del `.example` y permanece ignorado. Cada instalación debe crear secretos
propios y protegerlos con permisos `600` o `640` según el consumidor.
