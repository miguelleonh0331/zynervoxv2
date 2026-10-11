-- Same isolated runtime identity; new dialer tables only.
GRANT SELECT, INSERT, UPDATE, DELETE ON zynervox_core.v2_dialer_pause_types TO 'zynervoxv2_core'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON zynervox_core.v2_dialer_agent_status TO 'zynervoxv2_core'@'127.0.0.1';
GRANT SELECT, INSERT, UPDATE, DELETE ON zynervox_core.v2_dialer_agent_pauses TO 'zynervoxv2_core'@'127.0.0.1';
