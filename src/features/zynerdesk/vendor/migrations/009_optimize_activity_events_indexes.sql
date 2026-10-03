-- Optimizacion de indices en agent_activity_events, sin tocar datos ni
-- codigo de aplicacion.
--
-- Diagnostico (EXPLAIN real contra produccion, agente con mas eventos):
-- ninguna consulta del backend filtra agent_activity_events por
-- event_type ni por process_name en un WHERE (se verifico con grep
-- exhaustivo sobre server.js). Pese a eso, idx_activity_agent_type_time
-- competia con idx_activity_agent_type_time para las consultas reales de
-- /api/history (activityRows, summaryRows), que filtran por
-- agent_id+event_time y ordenan por event_time -- MySQL elegia el indice
-- equivocado (idx_activity_agent_type_time) en vez de
-- idx_activity_agent_time (que calza exacto con filtro+orden), forzando
-- un "Using filesort" evitable en cada carga de activity.html.
--
-- idx_activity_process_time tampoco lo usa ninguna consulta; se elimina
-- porque solo agrega costo de escritura (cada evento insertado mantiene
-- este indice) sin beneficio de lectura.
--
-- Se conserva intacto idx_activity_agent_time (agent_id, event_time),
-- que es el que SI usan todas las consultas reales.

DROP INDEX idx_activity_agent_type_time ON agent_activity_events;
DROP INDEX idx_activity_process_time ON agent_activity_events;
