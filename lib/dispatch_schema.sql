CREATE TABLE IF NOT EXISTS dispatch_stores (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 name VARCHAR(160) NOT NULL,
 address VARCHAR(500) NOT NULL,
 phone VARCHAR(80) NOT NULL DEFAULT '',
 map_url VARCHAR(2048) NOT NULL DEFAULT '',
 lat DECIMAL(10,7) NULL,
 lng DECIMAL(10,7) NULL,
 coord_source VARCHAR(16) NOT NULL DEFAULT 'manual',
 geocoded_at DATETIME NULL,
 expires_at DATETIME NULL,
 enabled TINYINT(1) NOT NULL DEFAULT 1,
 revision INT UNSIGNED NOT NULL DEFAULT 1,
 created_at DATETIME NOT NULL,
 updated_at DATETIME NOT NULL,
 updated_by_admin_id BIGINT UNSIGNED NOT NULL,
 KEY idx_dispatch_stores_active (enabled,id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
CREATE TABLE IF NOT EXISTS dispatch_history (
 id BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
 order_id BIGINT UNSIGNED NOT NULL,
 revision INT UNSIGNED NOT NULL,
 store_id BIGINT UNSIGNED NULL,
 name VARCHAR(160) NULL,
 address VARCHAR(500) NULL,
 map_url VARCHAR(2048) NULL,
 admin_id BIGINT UNSIGNED NULL,
 partner_id BIGINT UNSIGNED NULL,
 reason VARCHAR(1000) NULL,
 created_at DATETIME NOT NULL,
 UNIQUE KEY uq_dispatch_history_revision (order_id,revision),
 KEY idx_dispatch_history_partner (partner_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;
