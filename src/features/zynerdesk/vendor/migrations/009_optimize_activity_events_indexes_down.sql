-- Rollback exclusivo de la migracion 009: recrea los dos indices tal cual
-- estaban definidos en la migracion 004, por si en el futuro alguna
-- consulta nueva filtra por event_type o process_name y los necesita.

CREATE INDEX idx_activity_agent_type_time ON agent_activity_events (agent_id, event_type, event_time);
CREATE INDEX idx_activity_process_time ON agent_activity_events (process_name, event_time);
