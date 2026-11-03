-- Central · actualizar A PEDIDO (3-nov-2026, Joe): nunca solas; las pide Joe desde la central o el cliente desde su CRM.
ALTER TABLE hijas
  ADD COLUMN IF NOT EXISTS actualizar_pedido DATETIME NULL,
  ADD COLUMN IF NOT EXISTS actualizar_por VARCHAR(30) NOT NULL DEFAULT '';

CREATE TABLE IF NOT EXISTS actualizaciones (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  hija_id     INT UNSIGNED NOT NULL,
  pieza       VARCHAR(10) NOT NULL DEFAULT 'crm',
  desde       VARCHAR(20) NOT NULL DEFAULT '',
  hasta       VARCHAR(20) NOT NULL DEFAULT '',
  por         VARCHAR(30) NOT NULL DEFAULT '',          -- 'joe' (central) o 'cliente' (su CRM)
  estado      ENUM('pedida','enviada','ok','fallo') NOT NULL DEFAULT 'pedida',
  mensaje     VARCHAR(500) NOT NULL DEFAULT '',
  pedida_en   DATETIME NOT NULL,
  terminada_en DATETIME NULL,
  KEY k_hija (hija_id, id),
  CONSTRAINT fk_act_hija FOREIGN KEY (hija_id) REFERENCES hijas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
