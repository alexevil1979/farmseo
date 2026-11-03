-- migrate:up

CREATE TABLE `proxy_assignments` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `account_id` BIGINT UNSIGNED NOT NULL,
    `proxy_id` BIGINT UNSIGNED NOT NULL,
    `assigned_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `released_at` DATETIME(3) NULL DEFAULT NULL,
    `is_current` TINYINT(1) NOT NULL DEFAULT 1,
    PRIMARY KEY (`id`),
    KEY `idx_proxy_assignments_account_id` (`account_id`),
    KEY `idx_proxy_assignments_proxy_id` (`proxy_id`),
    KEY `idx_proxy_assignments_is_current` (`is_current`),
    CONSTRAINT `fk_proxy_assignments_account_id` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_proxy_assignments_proxy_id` FOREIGN KEY (`proxy_id`) REFERENCES `proxies` (`id`) ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- migrate:down

DROP TABLE IF EXISTS `proxy_assignments`;
