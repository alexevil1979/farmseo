-- migrate:up

CREATE TABLE `phones` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `number` VARCHAR(32) NOT NULL,
    `provider` VARCHAR(32) NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'rented',
    `account_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `external_id` VARCHAR(64) NULL DEFAULT NULL,
    `service` VARCHAR(32) NULL DEFAULT NULL,
    `country` VARCHAR(16) NULL DEFAULT NULL,
    `rented_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `expires_at` DATETIME(3) NULL DEFAULT NULL,
    `released_at` DATETIME(3) NULL DEFAULT NULL,
    `last_code` VARCHAR(32) NULL DEFAULT NULL,
    `meta` JSON NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_phones_number` (`number`),
    KEY `idx_phones_status` (`status`),
    KEY `idx_phones_provider` (`provider`),
    KEY `idx_phones_account_id` (`account_id`),
    KEY `idx_phones_expires_at` (`expires_at`),
    KEY `idx_phones_external_id` (`external_id`),
    CONSTRAINT `fk_phones_account_id` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- migrate:down

DROP TABLE IF EXISTS `phones`;
