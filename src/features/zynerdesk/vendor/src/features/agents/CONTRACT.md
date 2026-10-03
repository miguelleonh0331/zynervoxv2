# Contrato: agents

Responsable del registro, presencia, comandos e historial de agentes. Durante la
transición sus rutas se componen en `src/app.js`; los consumidores deben usar las API
HTTP `/api/agent/*`, `/api/agents` y `/api/commands/*`, nunca tablas directamente.
