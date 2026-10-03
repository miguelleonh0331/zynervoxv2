-- Conserva el semaforo calculado y permite una correccion manual auditable.

ALTER TABLE agent_daily_stats
  ADD COLUMN automatic_semaphore ENUM('green','yellow','red','gray') NOT NULL DEFAULT 'gray' AFTER semaphore,
  ADD COLUMN semaphore_overridden TINYINT(1) NOT NULL DEFAULT 0 AFTER automatic_semaphore,
  ADD COLUMN semaphore_override_by VARCHAR(80) NULL AFTER semaphore_overridden,
  ADD COLUMN semaphore_override_at DATETIME(3) NULL AFTER semaphore_override_by;

UPDATE agent_daily_stats SET automatic_semaphore = semaphore;
