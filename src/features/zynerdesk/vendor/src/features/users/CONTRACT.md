# Contrato: users

Expone `createUsersService({ pool })` para normalizar campañas, leer asignaciones y
decidir si un usuario puede acceder a un agente. Depende solo del pool y no modifica
sesiones, telemetría ni sockets.
