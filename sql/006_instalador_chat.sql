-- Central · fase 6b (4-nov-2026): el instalador también pone Chat y Robot (casilla marcada por defecto).
ALTER TABLE instalaciones
  ADD COLUMN IF NOT EXISTS con_chat     TINYINT(1)   NOT NULL DEFAULT 0  AFTER admin_email,
  ADD COLUMN IF NOT EXISTS dominio_chat VARCHAR(120) NOT NULL DEFAULT '' AFTER con_chat,
  ADD COLUMN IF NOT EXISTS nombre_robot VARCHAR(40)  NOT NULL DEFAULT '' AFTER dominio_chat;
