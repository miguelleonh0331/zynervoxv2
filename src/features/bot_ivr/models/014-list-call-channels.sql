-- Run against the isolated Bot IVR database, with dial engines stopped.
-- NULL marks lists not initialized yet; rerunning preserves saved selections.
ALTER TABLE zynervox_bot_lists ADD COLUMN IF NOT EXISTS call_channels SMALLINT UNSIGNED NULL DEFAULT NULL;
UPDATE zynervox_bot_lists l
SET call_channels=COALESCE((SELECT r.channels FROM zynervox_bot_dial_runs r
 WHERE r.list_id=l.list_id AND r.campaign_id=l.campaign_id
 AND r.channels IN (1,3,5,10,20,25,50,100)
 ORDER BY r.created_at DESC,r.run_id DESC LIMIT 1),3)
WHERE l.call_channels IS NULL;
ALTER TABLE zynervox_bot_lists MODIFY COLUMN call_channels SMALLINT UNSIGNED NOT NULL DEFAULT 3;
