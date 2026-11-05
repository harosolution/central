-- Central · fase 7 (5-nov-2026, Joe: «con 80 clientes no puedo pasarme el día mandando sudos»): la central pide actualizar
-- VARIAS piezas a la vez (agente, crm, robot, chat; actualizaciones.pieza ya existía) y la casilla «actualizar sola» (apagada:
-- la regla de Joe sigue siendo que solo se actualizan cuando él o el cliente lo piden, salvo que él la prenda en una hija).
ALTER TABLE hijas
  ADD COLUMN IF NOT EXISTS actualizar_piezas VARCHAR(60) NOT NULL DEFAULT '' AFTER actualizar_por,
  ADD COLUMN IF NOT EXISTS auto_actualizar TINYINT(1) NOT NULL DEFAULT 0 AFTER actualizar_piezas;
