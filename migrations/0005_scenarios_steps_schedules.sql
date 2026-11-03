-- migrate:up

CREATE TABLE `scenarios` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name` VARCHAR(128) NOT NULL,
    `type` VARCHAR(16) NOT NULL,
    `description` TEXT NULL DEFAULT NULL,
    `version` INT UNSIGNED NOT NULL DEFAULT 1,
    `is_active` TINYINT(1) NOT NULL DEFAULT 1,
    `defaults` JSON NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_scenarios_type` (`type`),
    KEY `idx_scenarios_is_active` (`is_active`),
    UNIQUE KEY `uq_scenarios_name_version` (`name`, `version`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `scenario_steps` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scenario_id` BIGINT UNSIGNED NOT NULL,
    `step_order` INT UNSIGNED NOT NULL,
    `action` VARCHAR(64) NOT NULL,
    `params` JSON NULL DEFAULT NULL,
    `timeout_sec` INT UNSIGNED NULL DEFAULT NULL,
    `on_fail` VARCHAR(32) NOT NULL DEFAULT 'abort',
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_scenario_steps_scenario_order` (`scenario_id`, `step_order`),
    KEY `idx_scenario_steps_scenario_id` (`scenario_id`),
    CONSTRAINT `fk_scenario_steps_scenario_id` FOREIGN KEY (`scenario_id`) REFERENCES `scenarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `scenario_schedules` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `scenario_id` BIGINT UNSIGNED NOT NULL,
    `cron_expr` VARCHAR(64) NOT NULL,
    `timezone` VARCHAR(64) NOT NULL DEFAULT 'UTC',
    `is_enabled` TINYINT(1) NOT NULL DEFAULT 1,
    `payload_override` JSON NULL DEFAULT NULL,
    `next_run_at` DATETIME(3) NULL DEFAULT NULL,
    `last_run_at` DATETIME(3) NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_scenario_schedules_scenario_id` (`scenario_id`),
    KEY `idx_scenario_schedules_next_run_at` (`next_run_at`),
    KEY `idx_scenario_schedules_is_enabled` (`is_enabled`),
    CONSTRAINT `fk_scenario_schedules_scenario_id` FOREIGN KEY (`scenario_id`) REFERENCES `scenarios` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- migrate:down

DROP TABLE IF EXISTS `scenario_schedules`;
DROP TABLE IF EXISTS `scenario_steps`;
DROP TABLE IF EXISTS `scenarios`;
