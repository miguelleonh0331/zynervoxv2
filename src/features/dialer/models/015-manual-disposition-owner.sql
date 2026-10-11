-- Snapshot of the initiating MANUAL agent; not conversation attribution.
ALTER TABLE v2_dialer_call_log
 ADD COLUMN IF NOT EXISTS disposition_agent_user VARCHAR(60) NULL,
 ADD INDEX IF NOT EXISTS dialer_pending_disposition_owner (disposition_agent_user,ended_at,disposition_code);
