-- Shared outbound route is distinct from the private postanswer callback.
ALTER TABLE v2_dialer_campaigns
  MODIFY COLUMN carrier_id VARCHAR(60) NULL,
  ADD COLUMN IF NOT EXISTS selected_dialplan_id BIGINT UNSIGNED NULL AFTER dialplan_id;
