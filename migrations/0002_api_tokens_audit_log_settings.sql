-- migrate:up

CREATE TABLE `api_tokens` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NOT NULL,
    `name` VARCHAR(128) NOT NULL,
    `token_hash` CHAR(64) NOT NULL,
    `abilities` JSON NULL DEFAULT NULL,
    `last_used_at` DATETIME(3) NULL DEFAULT NULL,
    `expires_at` DATETIME(3) NULL DEFAULT NULL,
    `revoked_at` DATETIME(3) NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_api_tokens_token_hash` (`token_hash`),
    KEY `idx_api_tokens_user_id` (`user_id`),
    CONSTRAINT `fk_api_tokens_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `audit_log` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `user_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `action` VARCHAR(64) NOT NULL,
    `entity_type` VARCHAR(64) NOT NULL,
    `entity_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `ip` VARCHAR(45) NULL DEFAULT NULL,
    `meta` JSON NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_audit_log_user_id` (`user_id`),
    KEY `idx_audit_log_entity` (`entity_type`, `entity_id`),
    KEY `idx_audit_log_created_at` (`created_at`),
    CONSTRAINT `fk_audit_log_user_id` FOREIGN KEY (`user_id`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `settings` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(128) NOT NULL,
    `value` JSON NOT NULL,
    `updated_by` BIGINT UNSIGNED NULL DEFAULT NULL,
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_settings_name` (`name`),
    CONSTRAINT `fk_settings_updated_by` FOREIGN KEY (`updated_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- migrate:down

DROP TABLE IF EXISTS `settings`;
DROP TABLE IF EXISTS `audit_log`;
DROP TABLE IF EXISTS `api_tokens`;
