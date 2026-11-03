-- Fase 5 (3-nov-2026): quién aceptó los términos del servicio y el encargo de tratamiento de datos (Ley 29733), y cuándo.
CREATE TABLE IF NOT EXISTS aceptaciones (
  id           INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  hija_id      INT UNSIGNED NOT NULL,
  version      VARCHAR(20)  NOT NULL,
  usuario      VARCHAR(120) NOT NULL DEFAULT '',
  correo       VARCHAR(160) NOT NULL DEFAULT '',
  empresa      VARCHAR(200) NOT NULL DEFAULT '',
  ruc          VARCHAR(20)  NOT NULL DEFAULT '',
  ip           VARCHAR(64)  NOT NULL DEFAULT '',
  aceptado_en  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
  KEY k_hija (hija_id, version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
