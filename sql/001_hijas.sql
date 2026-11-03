-- Central de hijas · fase 1 (3-nov-2026). Base propia `central`, separada del CRM.
CREATE TABLE IF NOT EXISTS hijas (
  id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre      VARCHAR(80)  NOT NULL,
  dominio     VARCHAR(120) NOT NULL DEFAULT '',
  servidor    VARCHAR(120) NOT NULL DEFAULT '',
  notas       VARCHAR(500) NOT NULL DEFAULT '',
  llave_hash  CHAR(64)     NOT NULL,
  activa      TINYINT(1)   NOT NULL DEFAULT 1,
  creada_en   DATETIME     NOT NULL,
  UNIQUE KEY uq_llave (llave_hash)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Último latido de cada hija (una fila por hija; se pisa en cada latido).
CREATE TABLE IF NOT EXISTS latidos (
  hija_id        INT UNSIGNED NOT NULL PRIMARY KEY,
  visto_en       DATETIME     NOT NULL,
  primer_latido  DATETIME     NULL,
  latidos_total  INT UNSIGNED NOT NULL DEFAULT 0,
  ip             VARCHAR(45)  NOT NULL DEFAULT '',
  crm_ver        VARCHAR(60)  NOT NULL DEFAULT '',
  chat_ver       VARCHAR(60)  NOT NULL DEFAULT '',
  robot_ver      VARCHAR(60)  NOT NULL DEFAULT '',
  agente_ver     VARCHAR(60)  NOT NULL DEFAULT '',
  servidor       VARCHAR(100) NOT NULL DEFAULT '',
  php_ver        VARCHAR(20)  NOT NULL DEFAULT '',
  disco_libre_gb DECIMAL(8,1) NULL,
  datos          TEXT         NULL,
  CONSTRAINT fk_latido_hija FOREIGN KEY (hija_id) REFERENCES hijas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
