# Contrato: agents

Responsable del registro, presencia, comandos e historial de agentes. Durante la
transición sus rutas se componen en `src/app.js`; los consumidores deben usar las API
HTTP `/api/agent/*`, `/api/agents` y `/api/commands/*`, nunca tablas directamente.

`GET /api/agents` excluye por defecto los equipos retirados. Solo un admin puede
consultar `GET /api/agents?retired=1` y ejecutar las acciones idempotentes
`POST /api/admin/agents/:id/retire` y `POST /api/admin/agents/:id/restore`.
El retiro es lógico: conserva historial y nuevos reportes no lo revierten.
