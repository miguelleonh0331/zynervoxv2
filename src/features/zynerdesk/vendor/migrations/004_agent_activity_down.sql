-- Rollback exclusivo de la migracion 004.
-- Ejecutar solo despues de volver al backend anterior y exportar estas tablas.

DROP TABLE IF EXISTS agent_activity_events;
DROP TABLE IF EXISTS agent_app_state;
DROP TABLE IF EXISTS agent_activity_status;
