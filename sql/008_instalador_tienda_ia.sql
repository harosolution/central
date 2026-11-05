-- Central · fase 6d (4-nov-2026): la instalación con Robot crea su cuenta de Recargas IA en streamy.
-- ia_cuenta_id = la cuenta (v2_ia_cuentas.id) que creó ESTA instalación: si falla, se desactiva; si se reintenta, se rota.
ALTER TABLE instalaciones
  ADD COLUMN IF NOT EXISTS ia_cuenta_id INT UNSIGNED NULL AFTER nombre_robot;
