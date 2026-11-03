-- migrate:up

CREATE TABLE `proxies` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `host` VARCHAR(255) NOT NULL,
    `port` INT UNSIGNED NOT NULL,
    `protocol` VARCHAR(16) NOT NULL DEFAULT 'http',
    `username` VARCHAR(128) NULL DEFAULT NULL,
    `password_enc` VARCHAR(512) NULL DEFAULT NULL,
    `country` CHAR(2) NULL DEFAULT NULL,
    `health` VARCHAR(16) NOT NULL DEFAULT 'unknown',
    `latency_ms` INT UNSIGNED NULL DEFAULT NULL,
    `last_check_at` DATETIME(3) NULL DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `meta` JSON NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_proxies_health` (`health`),
    KEY `idx_proxies_is_active` (`is_active`),
    UNIQUE KEY `uq_proxies_host_port_protocol` (`host`, `port`, `protocol`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `accounts` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `login` VARCHAR(255) NOT NULL,
    `password_enc` VARCHAR(512) NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'new',
    `trust_score` DECIMAL(5,2) NOT NULL DEFAULT 0.00,
    `phone` VARCHAR(32) NULL DEFAULT NULL,
    `group_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `tags` JSON NULL DEFAULT NULL,
    `notes` TEXT NULL DEFAULT NULL,
    `proxy_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `profile_path` VARCHAR(512) NULL DEFAULT NULL,
    `last_activity` DATETIME(3) NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_accounts_login` (`login`),
    KEY `idx_accounts_status` (`status`),
    KEY `idx_accounts_group_id` (`group_id`),
    KEY `idx_accounts_proxy_id` (`proxy_id`),
    KEY `idx_accounts_last_activity` (`last_activity`),
    CONSTRAINT `fk_accounts_proxy_id` FOREIGN KEY (`proxy_id`) REFERENCES `proxies` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `account_fingerprints` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `account_id` BIGINT UNSIGNED NOT NULL,
    `user_agent` VARCHAR(512) NOT NULL,
    `platform` VARCHAR(64) NULL DEFAULT NULL,
    `viewport_w` INT UNSIGNED NULL DEFAULT NULL,
    `viewport_h` INT UNSIGNED NULL DEFAULT NULL,
    `timezone` VARCHAR(64) NULL DEFAULT NULL,
    `locale` VARCHAR(16) NULL DEFAULT NULL,
    `webgl_hash` VARCHAR(64) NULL DEFAULT NULL,
    `canvas_hash` VARCHAR(64) NULL DEFAULT NULL,
    `fonts_hash` VARCHAR(64) NULL DEFAULT NULL,
    `extra` JSON NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_account_fingerprints_account_id` (`account_id`),
    CONSTRAINT `fk_account_fingerprints_account_id` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `account_sessions` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `account_id` BIGINT UNSIGNED NOT NULL,
    `worker_id` VARCHAR(64) NULL DEFAULT NULL,
    `vnc_port` INT UNSIGNED NULL DEFAULT NULL,
    `novnc_url` VARCHAR(512) NULL DEFAULT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'starting',
    `started_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `ended_at` DATETIME(3) NULL DEFAULT NULL,
    `meta` JSON NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_account_sessions_account_id` (`account_id`),
    KEY `idx_account_sessions_status` (`status`),
    KEY `idx_account_sessions_started_at` (`started_at`),
    CONSTRAINT `fk_account_sessions_account_id` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `account_cookies_snapshots` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `account_id` BIGINT UNSIGNED NOT NULL,
    `storage_path` VARCHAR(512) NOT NULL,
    `cookie_count` INT UNSIGNED NULL DEFAULT NULL,
    `checksum` CHAR(64) NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_account_cookies_snapshots_account_id` (`account_id`),
    KEY `idx_account_cookies_snapshots_created_at` (`created_at`),
    CONSTRAINT `fk_account_cookies_snapshots_account_id` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- migrate:down

DROP TABLE IF EXISTS `account_cookies_snapshots`;
DROP TABLE IF EXISTS `account_sessions`;
DROP TABLE IF EXISTS `account_fingerprints`;
DROP TABLE IF EXISTS `accounts`;
DROP TABLE IF EXISTS `proxies`;
