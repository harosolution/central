-- Central · fase 7c (5-nov-2026, Joe): la API de SHALOM del CRM (agencias y guías) como casilla del instalador.
-- DEFAULT 1: los instaladores ya generados que se vuelvan a mostrar salen con la casilla marcada; los nuevos guardan lo elegido.
ALTER TABLE instalaciones
  ADD COLUMN IF NOT EXISTS con_shalom TINYINT(1) NOT NULL DEFAULT 1 AFTER con_conector;
