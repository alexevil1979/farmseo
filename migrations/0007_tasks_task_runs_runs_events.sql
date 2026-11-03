-- migrate:up

CREATE TABLE `tasks` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `type` VARCHAR(16) NOT NULL,
    `priority` INT NOT NULL DEFAULT 0,
    `status` VARCHAR(16) NOT NULL DEFAULT 'pending',
    `account_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `scenario_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `campaign_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `keyword_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `target_id` BIGINT UNSIGNED NULL DEFAULT NULL,
    `payload` JSON NOT NULL,
    `attempts` INT UNSIGNED NOT NULL DEFAULT 0,
    `max_attempts` INT UNSIGNED NOT NULL DEFAULT 3,
    `scheduled_at` DATETIME(3) NULL DEFAULT NULL,
    `timeout` INT UNSIGNED NOT NULL DEFAULT 300,
    `started_at` DATETIME(3) NULL DEFAULT NULL,
    `finished_at` DATETIME(3) NULL DEFAULT NULL,
    `error` TEXT NULL DEFAULT NULL,
    `webhook_url` VARCHAR(2048) NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_tasks_status` (`status`),
    KEY `idx_tasks_type` (`type`),
    KEY `idx_tasks_priority_scheduled` (`priority`, `scheduled_at`),
    KEY `idx_tasks_account_id` (`account_id`),
    KEY `idx_tasks_scenario_id` (`scenario_id`),
    KEY `idx_tasks_campaign_id` (`campaign_id`),
    KEY `idx_tasks_status_scheduled` (`status`, `scheduled_at`),
    CONSTRAINT `fk_tasks_account_id` FOREIGN KEY (`account_id`) REFERENCES `accounts` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_scenario_id` FOREIGN KEY (`scenario_id`) REFERENCES `scenarios` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_campaign_id` FOREIGN KEY (`campaign_id`) REFERENCES `campaigns` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_keyword_id` FOREIGN KEY (`keyword_id`) REFERENCES `keywords` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_tasks_target_id` FOREIGN KEY (`target_id`) REFERENCES `targets` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `task_runs` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `task_id` BIGINT UNSIGNED NOT NULL,
    `attempt` INT UNSIGNED NOT NULL,
    `worker_id` VARCHAR(64) NULL DEFAULT NULL,
    `status` VARCHAR(16) NOT NULL,
    `started_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `finished_at` DATETIME(3) NULL DEFAULT NULL,
    `error` TEXT NULL DEFAULT NULL,
    `metrics` JSON NULL DEFAULT NULL,
    `screenshots` JSON NULL DEFAULT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_task_runs_task_attempt` (`task_id`, `attempt`),
    KEY `idx_task_runs_task_id` (`task_id`),
    KEY `idx_task_runs_status` (`status`),
    CONSTRAINT `fk_task_runs_task_id` FOREIGN KEY (`task_id`) REFERENCES `tasks` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `runs_events` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `task_run_id` BIGINT UNSIGNED NOT NULL,
    `level` VARCHAR(16) NOT NULL DEFAULT 'info',
    `event` VARCHAR(64) NOT NULL,
    `message` VARCHAR(1024) NULL DEFAULT NULL,
    `data` JSON NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    KEY `idx_runs_events_task_run_id` (`task_run_id`),
    KEY `idx_runs_events_created_at` (`created_at`),
    KEY `idx_runs_events_event` (`event`),
    CONSTRAINT `fk_runs_events_task_run_id` FOREIGN KEY (`task_run_id`) REFERENCES `task_runs` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- migrate:down

DROP TABLE IF EXISTS `runs_events`;
DROP TABLE IF EXISTS `task_runs`;
DROP TABLE IF EXISTS `tasks`;
