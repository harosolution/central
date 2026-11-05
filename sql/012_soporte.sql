-- Central · fase 7e (5-nov-2026, Joe): acceso de soporte HARO como casilla del instalador, y cómo entrar a cada hija.
-- DEFAULT 1 en con_soporte: los instaladores ya generados que se vuelvan a mostrar salen con la casilla marcada.
ALTER TABLE instalaciones
  ADD COLUMN IF NOT EXISTS con_soporte TINYINT(1) NOT NULL DEFAULT 1 AFTER con_shalom;
ALTER TABLE hijas
  ADD COLUMN IF NOT EXISTS soporte VARCHAR(160) NOT NULL DEFAULT '' AFTER servidor;
