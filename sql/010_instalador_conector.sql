-- Central · fase 7b (5-nov-2026, Joe): el conector de Claude (MCP) del robot como casilla del instalador.
ALTER TABLE instalaciones
  ADD COLUMN IF NOT EXISTS con_conector TINYINT(1) NOT NULL DEFAULT 0 AFTER nombre_robot;
