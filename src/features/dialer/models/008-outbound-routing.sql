-- NULL preserves old campaigns as unconfigured; '' is an explicit no-prefix route.
ALTER TABLE v2_dialer_campaigns
  ADD COLUMN IF NOT EXISTS outbound_prefix VARCHAR(20) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS drop_message VARCHAR(255) NULL DEFAULT NULL,
  ADD COLUMN IF NOT EXISTS amd_message VARCHAR(255) NULL DEFAULT NULL;
