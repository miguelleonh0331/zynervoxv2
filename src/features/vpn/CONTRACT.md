# Contrato: vpn

Arquitectura aprobada por ARCHITECT_AGENT, 2026-10-11.

## Entrada web

modules/vpn/index.php requiere administrador nivel9, sesi?n Zynervox.
GET panel/inventario/filtros, POST create/update/revoke/delete/download con CSRF.
Actor desde sesi?n y IP REMOTE_ADDR, nunca desde campos del navegador.
Inventario editable: display_name, email, agent_ref, notes. username inmutable,
?nico incluso despu?s del borrado l?gico. Sin renovaci?n/reutilizaci?n de CN.

## Servicio privado

POST /api JSON, token Bearer desde archivos privados fueraGit en PHP/backend.
Host127.0.0.1:18081 ?nicamente; sin CORS ni socketDocker. API publica
inventory/create/update/revoke/delete/download/access. Par?metros validados,
ovpn_manage con argumentos fijos sin shell, lock de operaciones PKI.
PKI autoridad de estado; SQLite reconciliado antes de operaciones.
Revocar actualiza CRL y desconecta por management loopback. Borrado l?gico
exige revocaci?n confirmada. FalloPKI impide borrar inventario. Descarga solo
activo, autorizada y auditada con no-store; perfil/clave/token fuera de logs.

## Auditor?a

Panel, inventario, operaci?n solicitada/resultado, cambios antes/despu?s,
descargas, denegaci?n acceso/CSRF; VPN_CONNECT/VPN_DISCONNECT con CN e IP.
Append-only mediante API/triggers; no inmutable frente a root. Consultas
muestran hasta200 eventos recientes con filtros exactos actor/target/action,
sin truncar almacenamiento. StatusOpenVPNv3 fresco para sesiones actuales;
?ltima conexi?n desde hooks habilitados, no reconstruye historial previo.
Hooks Unixsocket SO_PEERCRED root/nobody; conectar requiere auditar evento.
Historial queda aun si se elimina cuenta. Metadatos de inventario son auditables.

## Dependencias y errores

Core Auth/Config/sidebar; Python3 stdlib/SQLite; OpenVPN/EasyRSA base con digest.
Configausente/servicio ca?do/autorizaci?n inv?lida/falloPKI impiden operaci?n.
No crea usuariosZynervox ni modificaPBX; no reinicializa volumen ni PKI.
Endpoint LAN; sin garant?as de clienteWindows ni conectividadInternet.

## 2026-10-11 — Auditoría bajo demanda (sustituye límite200 anterior)

inventory no consulta ni devuelve historial. La vista inicial solo ofrece
Ver auditoría; view=audit solicita la acción privada audit. Página de20 eventos,
filtros exactos actor/target/action, before_id/after_id excluyentes y snapshot_id
fijo. Cursores enteros positivos64bit, navegación por id sin OFFSET/COUNTglobal.
Índices por filtro/id. Ambos sentidos conservan filtros/snapshot; nuevos eventos
aparecen al actualizar. Se mantienen todos los registros. Aprobado arquitecto.
