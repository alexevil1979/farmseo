-- migrate:up

CREATE TABLE `nodes` (
    `id` BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `address` VARCHAR(512) NOT NULL,
    `capacity` INT UNSIGNED NOT NULL DEFAULT 40,
    `last_hb` DATETIME(3) NULL DEFAULT NULL,
    `status` VARCHAR(16) NOT NULL DEFAULT 'online',
    `meta` JSON NULL DEFAULT NULL,
    `created_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3),
    `updated_at` DATETIME(3) NOT NULL DEFAULT CURRENT_TIMESTAMP(3) ON UPDATE CURRENT_TIMESTAMP(3),
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_nodes_address` (`address`),
    KEY `idx_nodes_status` (`status`),
    KEY `idx_nodes_last_hb` (`last_hb`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

ALTER TABLE `accounts`
    ADD COLUMN `assigned_node_id` BIGINT UNSIGNED NULL DEFAULT NULL AFTER `proxy_id`,
    ADD KEY `idx_accounts_assigned_node_id` (`assigned_node_id`),
    ADD CONSTRAINT `fk_accounts_assigned_node_id`
        FOREIGN KEY (`assigned_node_id`) REFERENCES `nodes` (`id`) ON DELETE SET NULL;

-- migrate:down

ALTER TABLE `accounts`
    DROP FOREIGN KEY `fk_accounts_assigned_node_id`,
    DROP KEY `idx_accounts_assigned_node_id`,
    DROP COLUMN `assigned_node_id`;

DROP TABLE IF EXISTS `nodes`;
