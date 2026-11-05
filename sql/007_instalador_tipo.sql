-- Central · fase 6c (4-nov-2026): un código también puede ser para AGREGAR Chat y Robot a una hija ya instalada.
ALTER TABLE instalaciones
  ADD COLUMN IF NOT EXISTS tipo ENUM('nueva','chat') NOT NULL DEFAULT 'nueva' AFTER hija_id;
