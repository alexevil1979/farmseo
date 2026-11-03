-- migrate:up

CREATE TABLE `campaigns` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(128) NOT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'draft',
    `scenario_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `budget_tasks` INT UNSIGNED NULL DEFAULT NULL,
    `starts_at` DATETIME(3) NULL DEFAULT NULL,
    `ends_at` DATETIME(3) NULL DEFAULT NULL,
    `meta` JSON NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_campaigns_name` (`name`),
    KEY `idx_campaigns_status` (`status`),
    KEY `idx_campaigns_scenario_id` (`scenario_id`),
    CONSTRAINT `fk_campaigns_scenario_id` FOREIGN KEY (`scenario_id`) REFERENCES `scenarios` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `keywords` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `phrase` VARCHAR(512) NOT NULL,
    `lang` VARCHAR(16) NULL DEFAULT NULL,
    `geo` VARCHAR(64) NULL DEFAULT NULL,
    `priority` INT NOT NULL DEFAULT 0,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `meta` JSON NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_keywords_campaign_id` (`campaign_id`),
    KEY `idx_keywords_phrase` (`phrase`(191)),
    KEY `idx_keywords_is_active` (`is_active`),
    CONSTRAINT `fk_keywords_campaign_id` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `targets` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `campaign_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `url` VARCHAR(2048) NOT NULL,
    `domain` VARCHAR(255) NULL DEFAULT NULL,
    `title` VARCHAR(512) NULL DEFAULT NULL,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `meta` JSON NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_targets_campaign_id` (`campaign_id`),
    KEY `idx_targets_domain` (`domain`),
    KEY `idx_targets_is_active` (`is_active`),
    CONSTRAINT `fk_targets_campaign_id` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- migrate:down

DROP TABLE IF EXISTS `targets`;
DROP TABLE IF EXISTS `keywords`;
DROP TABLE IF EXISTS `campaigns`;
