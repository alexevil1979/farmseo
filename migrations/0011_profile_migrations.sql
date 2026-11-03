-- migrate:up

CREATE TABLE `profile_migrations` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `account_id` BIGINT UNSIGNED NOT NULL,
    `from_node_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `to_node_id` BIGINT UNSIGNED NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
    `archive_checksum` CHAR(64) NULL DEFAULT NULL,
    `error` TEXT NULL DEFAULT NULL,
    `started_at` DATETIME(3) NULL DEFAULT NULL,
    `finished_at` DATETIME(3) NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_profile_migrations_account_id` (`account_id`),
    KEY `idx_profile_migrations_status` (`status`),
    KEY `idx_profile_migrations_to_node_id` (`to_node_id`),
    KEY `idx_profile_migrations_account_status` (`account_id`, `status`),
    CONSTRAINT `fk_profile_migrations_account_id`
        FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_profile_migrations_from_node_id`
        FOREIGN KEY (`from_node_id`) REFERENCES `nodes` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_profile_migrations_to_node_id`
        FOREIGN KEY (`to_node_id`) REFERENCES `nodes` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- migrate:down

DROP TABLE IF EXISTS `profile_migrations`;
