-- Central · fase 7f (5-nov-2026, Joe): el nombre corto de la tienda (hasta 20), aparte de la razón social.
-- Sale arriba del menú del CRM, en el chat y en el saludo del robot. Vacío = se arma con la razón social.
ALTER TABLE instalaciones
  ADD COLUMN IF NOT EXISTS tienda VARCHAR(20) NOT NULL DEFAULT '' AFTER empresa;
