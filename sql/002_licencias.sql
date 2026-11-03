-- Central de hijas · fase 3 (3-nov-2026): planes, licencias, pagos e ingresos. Aditiva.

-- Planes que define Joe (no van en el código). funciones/limites en JSON.
CREATE TABLE IF NOT EXISTS planes (
  id         INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  nombre     VARCHAR(60)  NOT NULL,
  precio     DECIMAL(10,2) NOT NULL DEFAULT 0,
  meses      TINYINT UNSIGNED NOT NULL DEFAULT 1,      -- cuánto dura lo que se paga
  funciones  TEXT NULL,                                -- ["robot_ia","chat_automatico",...]
  limites    TEXT NULL,                                -- {"usuarios":5,"whatsapp":1,"pedidos_mes":0}  (0 = sin límite)
  descripcion VARCHAR(300) NOT NULL DEFAULT '',
  activo     TINYINT(1) NOT NULL DEFAULT 1,
  orden      INT NOT NULL DEFAULT 0,
  creado_en  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Licencia de cada hija
ALTER TABLE hijas
  ADD COLUMN IF NOT EXISTS plan_id INT UNSIGNED NULL,
  ADD COLUMN IF NOT EXISTS vence_en DATE NULL,
  ADD COLUMN IF NOT EXISTS contacto_nombre VARCHAR(100) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS contacto_telefono VARCHAR(20) NOT NULL DEFAULT '',
  ADD COLUMN IF NOT EXISTS streamy_cuenta_id INT NULL;   -- su cuenta de Recargas IA (para contar las IAs que compra)

-- Pagos de licencia (Yape/Plin validados contra la bandeja Yape del CRM de TECNOVA, o registrados a mano)
CREATE TABLE IF NOT EXISTS pagos (
  id               INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
  hija_id          INT UNSIGNED NOT NULL,
  plan_id          INT UNSIGNED NULL,
  plan_nombre      VARCHAR(60) NOT NULL DEFAULT '',
  periodos         TINYINT UNSIGNED NOT NULL DEFAULT 1,
  meses            SMALLINT UNSIGNED NOT NULL DEFAULT 1,
  monto            DECIMAL(10,2) NOT NULL,
  metodo           ENUM('YAPE','PLIN','EFECTIVO','TRANSFERENCIA','CORTESIA') NOT NULL DEFAULT 'YAPE',
  estado           ENUM('pendiente','pagado','vencido','anulado') NOT NULL DEFAULT 'pendiente',
  codigo_seguridad CHAR(3) NULL,
  yape_id          INT NULL,
  yape_nombre      VARCHAR(120) NULL,
  yape_fecha       DATETIME NULL,
  vence_antes      DATE NULL,
  vence_despues    DATE NULL,
  detalle          VARCHAR(255) NULL,
  nota             VARCHAR(255) NOT NULL DEFAULT '',
  origen           ENUM('hija','central') NOT NULL DEFAULT 'hija',
  intentos         INT NOT NULL DEFAULT 0,
  ultimo_intento   DATETIME NULL,
  creado_en        DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  pagado_en        DATETIME NULL,
  UNIQUE KEY uq_yape (yape_id),
  KEY k_hija (hija_id, estado),
  KEY k_pagado (estado, pagado_en),
  CONSTRAINT fk_pago_hija FOREIGN KEY (hija_id) REFERENCES hijas(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Ajustes de la central (número de Yape, días de aviso y de gracia…)
CREATE TABLE IF NOT EXISTS ajustes (
  k VARCHAR(60) NOT NULL PRIMARY KEY,
  v TEXT NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
INSERT IGNORE INTO ajustes (k, v) VALUES ('aviso_dias', '7'), ('gracia_dias', '3'), ('yape_numero', ''), ('yape_titular', ''), ('plin_numero', ''), ('nota_cliente', '');
