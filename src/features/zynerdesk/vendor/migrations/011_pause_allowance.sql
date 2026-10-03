-- Metricas para el modelo proporcional de pausas.

ALTER TABLE agent_daily_stats
  ADD COLUMN assisted_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER foreground_seconds,
  ADD COLUMN allowed_pause_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER unclassified_break_seconds,
  ADD COLUMN excess_pause_seconds INT UNSIGNED NOT NULL DEFAULT 0 AFTER allowed_pause_seconds;
