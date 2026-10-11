-- Fase 4: cada campaña gestiona su propia entrada de dialplan (generada,
-- ver Dialer::syncDialplan) a traves de v2_dialplans; guardamos el id para
-- actualizar la misma fila en vez de crear una nueva cada vez.
ALTER TABLE v2_dialer_campaigns
  ADD COLUMN IF NOT EXISTS dialplan_id BIGINT UNSIGNED NULL AFTER queue_name;
