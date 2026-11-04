-- Central · fase 6: instalador de una línea (4-nov-2026). Un código de un solo uso por hija nueva.
-- Solo se guarda el sha256 del código. Se puede canjear de nuevo mientras ninguna instalación termine bien.
CREATE TABLE IF NOT EXISTS instalaciones (
  id           INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  hija_id      INT UNSIGNED NOT NULL,
  codigo_hash  CHAR(64)     NOT NULL,
  empresa      VARCHAR(120) NOT NULL DEFAULT '',
  admin_nombre VARCHAR(120) NOT NULL DEFAULT '',
  admin_email  VARCHAR(120) NOT NULL DEFAULT '',
  estado       ENUM('pendiente','canjeado','instalada','fallo','anulado') NOT NULL DEFAULT 'pendiente',
  intentos     SMALLINT UNSIGNED NOT NULL DEFAULT 0,
  servidor     VARCHAR(120) NOT NULL DEFAULT '',
  ip           VARCHAR(45)  NOT NULL DEFAULT '',
  version      VARCHAR(20)  NOT NULL DEFAULT '',
  mensaje      VARCHAR(500) NOT NULL DEFAULT '',
  por          VARCHAR(30)  NOT NULL DEFAULT '',
  creada_en    DATETIME     NOT NULL,
  vence_en     DATETIME     NOT NULL,
  canjeado_en  DATETIME     NULL,
  terminada_en DATETIME     NULL,
  UNIQUE KEY uq_codigo (codigo_hash),
  KEY k_hija (hija_id, id),
  CONSTRAINT fk_inst_hija FOREIGN KEY (hija_id) REFERENCES hijas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
