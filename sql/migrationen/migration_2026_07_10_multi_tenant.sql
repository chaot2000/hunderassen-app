-- =====================================================================
-- Migration 2026-07-10 (überarbeitet 2026-10-15): Mandantenfähigkeit
--
-- Shared Database + Shared Schema: tenant_id in users, dogs, halter,
-- zuechter, tests. Rassen, Tags und Aktivitäten bleiben global.
--
-- Idempotent: jeder Schritt prüft vorher über information_schema, ob er
-- schon erledigt ist. Ein zweiter Lauf ändert nichts. Läuft auf
-- MariaDB 10.11 (all-inkl) und MySQL 8.x (Laragon), ohne Stored
-- Procedures, also auch im SQL-Fenster von phpMyAdmin.
--
-- Nach der Migration ist das älteste Admin-Konto Super-Admin, falls es
-- noch keinen gibt. Siehe DEPLOYMENT_MULTI_TENANT.md.
--
-- Zurück: sql/migrationen/rollback_multi_tenant.sql
--
-- Muster jedes Blocks:
--   SET @sql := IF(<schon erledigt?>, 'DO 0', '<ALTER ...>');
--   PREPARE / EXECUTE / DEALLOCATE
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. Tabelle tenants und Default-Mandant (id 1)
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tenants` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100) NOT NULL,
    `subdomain`   VARCHAR(50)  NOT NULL,
    `is_active`   TINYINT(1)   NOT NULL DEFAULT 1,
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_subdomain` (`subdomain`),
    KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT IGNORE INTO `tenants` (`id`, `name`, `subdomain`, `is_active`, `created_at`)
VALUES (1, 'Default', 'default', 1, NOW());

-- ---------------------------------------------------------------------
-- 2. users: tenant_id (bestehende Zeilen -> Mandant 1), Index, FK
-- ---------------------------------------------------------------------
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tenant_id'), 'DO 0', 'ALTER TABLE `users` ADD COLUMN `tenant_id` INT UNSIGNED NULL AFTER `id`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE `users` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
ALTER TABLE `users` MODIFY COLUMN `tenant_id` INT UNSIGNED NOT NULL;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_tenant_id'), 'DO 0', 'ALTER TABLE `users` ADD INDEX `idx_tenant_id` (`tenant_id`)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_tenant' AND CONSTRAINT_TYPE = 'FOREIGN KEY'), 'DO 0', 'ALTER TABLE `users` ADD CONSTRAINT `fk_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 3. dogs: tenant_id (bestehende Zeilen -> Mandant 1), Index, FK
-- ---------------------------------------------------------------------
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dogs' AND COLUMN_NAME = 'tenant_id'), 'DO 0', 'ALTER TABLE `dogs` ADD COLUMN `tenant_id` INT UNSIGNED NULL AFTER `id`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE `dogs` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
ALTER TABLE `dogs` MODIFY COLUMN `tenant_id` INT UNSIGNED NOT NULL;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dogs' AND INDEX_NAME = 'idx_tenant_id'), 'DO 0', 'ALTER TABLE `dogs` ADD INDEX `idx_tenant_id` (`tenant_id`)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'dogs' AND CONSTRAINT_NAME = 'fk_dogs_tenant' AND CONSTRAINT_TYPE = 'FOREIGN KEY'), 'DO 0', 'ALTER TABLE `dogs` ADD CONSTRAINT `fk_dogs_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 4. halter: tenant_id (bestehende Zeilen -> Mandant 1), Index, FK
-- ---------------------------------------------------------------------
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'halter' AND COLUMN_NAME = 'tenant_id'), 'DO 0', 'ALTER TABLE `halter` ADD COLUMN `tenant_id` INT UNSIGNED NULL AFTER `id`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE `halter` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
ALTER TABLE `halter` MODIFY COLUMN `tenant_id` INT UNSIGNED NOT NULL;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'halter' AND INDEX_NAME = 'idx_tenant_id'), 'DO 0', 'ALTER TABLE `halter` ADD INDEX `idx_tenant_id` (`tenant_id`)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'halter' AND CONSTRAINT_NAME = 'fk_halter_tenant' AND CONSTRAINT_TYPE = 'FOREIGN KEY'), 'DO 0', 'ALTER TABLE `halter` ADD CONSTRAINT `fk_halter_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 5. zuechter: tenant_id (bestehende Zeilen -> Mandant 1), Index, FK
-- ---------------------------------------------------------------------
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zuechter' AND COLUMN_NAME = 'tenant_id'), 'DO 0', 'ALTER TABLE `zuechter` ADD COLUMN `tenant_id` INT UNSIGNED NULL AFTER `id`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE `zuechter` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
ALTER TABLE `zuechter` MODIFY COLUMN `tenant_id` INT UNSIGNED NOT NULL;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zuechter' AND INDEX_NAME = 'idx_tenant_id'), 'DO 0', 'ALTER TABLE `zuechter` ADD INDEX `idx_tenant_id` (`tenant_id`)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'zuechter' AND CONSTRAINT_NAME = 'fk_zuechter_tenant' AND CONSTRAINT_TYPE = 'FOREIGN KEY'), 'DO 0', 'ALTER TABLE `zuechter` ADD CONSTRAINT `fk_zuechter_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 6. tests: tenant_id (bestehende Zeilen -> Mandant 1), Index, FK
-- ---------------------------------------------------------------------
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND COLUMN_NAME = 'tenant_id'), 'DO 0', 'ALTER TABLE `tests` ADD COLUMN `tenant_id` INT UNSIGNED NULL AFTER `id`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
UPDATE `tests` SET `tenant_id` = 1 WHERE `tenant_id` IS NULL;
ALTER TABLE `tests` MODIFY COLUMN `tenant_id` INT UNSIGNED NOT NULL;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND INDEX_NAME = 'idx_tenant_id'), 'DO 0', 'ALTER TABLE `tests` ADD INDEX `idx_tenant_id` (`tenant_id`)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND CONSTRAINT_NAME = 'fk_tests_tenant' AND CONSTRAINT_TYPE = 'FOREIGN KEY'), 'DO 0', 'ALTER TABLE `tests` ADD CONSTRAINT `fk_tests_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 7. Eindeutigkeit pro Mandant statt global
--    users.uq_username        -> uq_tenant_username (tenant_id, username)
--    tests.uq_test_name       -> uq_tenant_test_name (tenant_id, name)
--    Neuer Schlüssel zuerst, dann der alte weg.
-- ---------------------------------------------------------------------
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_tenant_username'), 'DO 0', 'ALTER TABLE `users` ADD UNIQUE KEY `uq_tenant_username` (`tenant_id`, `username`)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_username'), 'ALTER TABLE `users` DROP INDEX `uq_username`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND INDEX_NAME = 'uq_tenant_test_name'), 'DO 0', 'ALTER TABLE `tests` ADD UNIQUE KEY `uq_tenant_test_name` (`tenant_id`, `name`)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND INDEX_NAME = 'uq_test_name'), 'ALTER TABLE `tests` DROP INDEX `uq_test_name`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 8. login_attempts: Fehlversuche pro Mandant zählen. Alte Einträge
--    behalten tenant_id NULL und zählen für keinen Mandanten mehr.
-- ---------------------------------------------------------------------
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'tenant_id'), 'DO 0', 'ALTER TABLE `login_attempts` ADD COLUMN `tenant_id` INT UNSIGNED NULL AFTER `id`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'idx_tenant_time'), 'DO 0', 'ALTER TABLE `login_attempts` ADD INDEX `idx_tenant_time` (`tenant_id`, `attempted_at`)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 9. Super-Admin: Spalte, und falls noch keiner existiert, wird das
--    älteste Admin-Konto in Mandant 1 Super-Admin.
-- ---------------------------------------------------------------------
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_super_admin'), 'DO 0', 'ALTER TABLE `users` ADD COLUMN `is_super_admin` TINYINT(1) NOT NULL DEFAULT 0 AFTER `role`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @super_id := IF(
    EXISTS(SELECT 1 FROM `users` WHERE `is_super_admin` = 1),
    NULL,
    (SELECT MIN(`id`) FROM `users` WHERE `role` = 'admin' AND `tenant_id` = 1)
);
UPDATE `users` SET `is_super_admin` = 1 WHERE `id` = @super_id;

-- Kontrolle (Ausgabe im SQL-Fenster):
SELECT `id`, `username`, `role`, `is_super_admin` FROM `users` WHERE `is_super_admin` = 1;
