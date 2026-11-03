-- migrate:up

CREATE TABLE `metrics` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `entity_type` VARCHAR(64) NOT NULL,
    `entity_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `name` VARCHAR(64) NOT NULL,
    `value` DECIMAL(18,6) NOT NULL,
    `unit` VARCHAR(16) NULL DEFAULT NULL,
    `captured_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `meta` JSON NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_metrics_entity` (`entity_type`, `entity_id`),
    KEY `idx_metrics_name_captured` (`name`, `captured_at`),
    KEY `idx_metrics_captured_at` (`captured_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `alerts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `severity` VARCHAR(16) NOT NULL DEFAULT 'warn',
    `source` VARCHAR(64) NOT NULL,
    `message` VARCHAR(1024) NOT NULL,
    `entity_type` VARCHAR(64) NULL DEFAULT NULL,
    `entity_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `is_acked` TINYINT(1) NOT NULL DEFAULT 0,
    `acked_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `acked_at` DATETIME(3) NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `meta` JSON NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_alerts_severity` (`severity`),
    KEY `idx_alerts_is_acked` (`is_acked`),
    KEY `idx_alerts_created_at` (`created_at`),
    KEY `idx_alerts_entity` (`entity_type`, `entity_id`),
    CONSTRAINT `fk_alerts_acked_by` FOREIGN KEY (`acked_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `blacklists` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `kind` VARCHAR(32) NOT NULL,
    `value` VARCHAR(512) NOT NULL,
    `reason` VARCHAR(255) NULL DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `expires_at` DATETIME(3) NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_blacklists_kind_value` (`kind`, `value`),
    KEY `idx_blacklists_is_active` (`is_active`),
    KEY `idx_blacklists_expires_at` (`expires_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- migrate:down

DROP TABLE IF EXISTS `blacklists`;
DROP TABLE IF EXISTS `alerts`;
DROP TABLE IF EXISTS `metrics`;
