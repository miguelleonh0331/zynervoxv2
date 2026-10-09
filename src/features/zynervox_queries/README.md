# Módulo: zynervox_queries

## Propósito

Centralizar conexión PHP/PDO y consultas por motor y módulo, con contratos de repositorio.

## Responsabilidad

- Validar motor y conexión; seleccionar adaptadores mediante factory pública.
- Encapsular SQL, identidad, bloqueos y transacciones bajo cada motor.
- Ofrecer operaciones de administración nueva Bot IVR sin SQL en páginas.

## No responsabilidad

- Autenticación, CSRF, interfaz, secretos o reglas de negocio.
- Workers Python, motor legacy, migración de datos o selección automática de motor.
- Compatibilidad con motores todavía sin adaptador y esquema propios.

## Estructura

Código desplegable: `app/web/zynervox_queries/` (PHP 7.4+ y PDO MySQL).

```text
connection.php                 conexión común; no contiene secretos
factory.php                    selección pública del repositorio
contracts/BotIvrRepository.php operaciones neutrales del consumidor
mysql/bot_ivr/campaigns.php     consultas de campañas
mysql/bot_ivr/lists.php         consultas de listas
mysql/bot_ivr/leads.php         carga, conteos y transacciones
mysql/bot_ivr/repository.php    implementación del contrato
```

MySQL y MariaDB comparten implementación; una configuración antigua sin engine
se interpreta como mysql. No se ofrece SQL Server/PostgreSQL hasta implementar
adaptadores, migraciones y pruebas. La factory usa selección fija; nunca carga
rutas proporcionadas por el usuario. La base del consumidor Bot IVR sigue zynervox.

## Dependencias principales

- PDO MySQL, PHP 7.4+; ninguna librería Python ni servicio intermediario.

## Casos principales

Bot IVR llama `bot_ivr_repository()` y consume BotIvrRepository. Su configuración
se guarda exclusivamente con el botón obligatorio existente; agrega engine.
Otros módulos pueden incorporarse añadiendo su contrato y subcarpetas por motor.
No deben importar traits ni implementaciones privadas.

## Incorporar un módulo o motor

1. Definir un contrato de operaciones y resultados aprobado por arquitectura.
2. Crear `<motor>/<modulo>/` con consultas y repositorio; mantener validación de negocio en el consumidor.
3. Registrar selección fija en factory. Evitar archivos duplicados PHP/Python.
4. Añadir/adaptar esquema, tipos, IDs, tiempos, bloqueos y transacciones propios del motor.
5. Ejecutar la misma suite contractual y pruebas de integración antes de habilitar la opción en UI.
6. Migrar datos aparte y conectar al consumidor por su configuración.

## Pruebas

`php src/features/zynervox_queries/tests/connection-db.php /ruta/web/bot_ivr`
compara lectura con SQL previo usando MySQL/MariaDB contra el servidor configurado,
comprueba configuración antigua y rechazos sin alterar secretos.
Pruebas Bot IVR de campañas y carga usan rollback. Estas pruebas en mirmidon
validan MariaDB 10.6.14 mediante PDO MySQL; no certifican otros servidores/versiones.

## Notas para agentes

Leer README, CONTRACT y agents/_DEFAULT_MODULE_AGENT.md. Cambios transversales
requieren ARCHITECT_AGENT. No tocar otros consumidores ni workers automáticamente.


## Reemplazo de base por lista (2026-10-05)

Reemplazar base valida TXT antes de escribir y sustituye exclusivamente los leads
de la lista abierta. Duplicados se descartan dentro del nuevo archivo. Archivos
sin filas válidas conservan la base anterior. Bloqueo del padre, DELETE por list_id
e INSERT comparten transacción; fallo revierte; transacción externa usa savepoint.
No modifica metadatos de lista/campaña ni otras listas. Prueba list-import-db.php
verifica reemplazo, reupload, actualización de teléfono retenido, archivo vacío,
fallo tras borrar con rollback y aislamiento entre listas; datos de prueba revertidos.
