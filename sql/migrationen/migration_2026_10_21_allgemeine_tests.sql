-- =====================================================================
-- Migration 2026-10-21: Allgemeine Tests für alle Organisationen (#25)
--
-- tests.tenant_id NULL = allgemeiner Test, sichtbar in allen
-- Organisationen, bearbeitbar nur vom Super-Admin. Namen bleiben je
-- Organisation und unter den allgemeinen Tests eindeutig: dafür die
-- berechnete Spalte scope_id (0 = allgemein) im Unique-Key.
--
-- test_ausblendungen: eine Organisation blendet einen allgemeinen Test
-- für sich aus (fehlt dann in der Auswahl für neue Durchführungen).
--
-- Bestehende Daten ändern sich nicht. Idempotent, läuft auf MariaDB
-- 10.11 und MySQL 8.x, auch im SQL-Fenster von phpMyAdmin.
-- Zurück: sql/migrationen/rollback_allgemeine_tests.sql
-- =====================================================================

SET NAMES utf8mb4;

-- tenant_id darf leer sein
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND COLUMN_NAME = 'tenant_id' AND IS_NULLABLE = 'NO'), 'ALTER TABLE `tests` MODIFY `tenant_id` INT UNSIGNED NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- scope_id: Organisation oder 0 für allgemein
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND COLUMN_NAME = 'scope_id'), 'DO 0', 'ALTER TABLE `tests` ADD COLUMN `scope_id` INT UNSIGNED AS (IFNULL(`tenant_id`, 0)) STORED');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Eindeutiger Name je scope_id statt je tenant_id
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND INDEX_NAME = 'uq_scope_test_name'), 'DO 0', 'ALTER TABLE `tests` ADD UNIQUE KEY `uq_scope_test_name` (`scope_id`, `name`)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND INDEX_NAME = 'uq_tenant_test_name'), 'ALTER TABLE `tests` DROP INDEX `uq_tenant_test_name`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

CREATE TABLE IF NOT EXISTS `test_ausblendungen` (
    `tenant_id`  INT UNSIGNED NOT NULL,
    `test_id`    INT UNSIGNED NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT UNSIGNED NULL,
    PRIMARY KEY (`tenant_id`, `test_id`),
    KEY `idx_ausbl_test` (`test_id`),
    KEY `idx_ausbl_created_by` (`created_by`),
    CONSTRAINT `fk_ausbl_tenant` FOREIGN KEY (`tenant_id`)  REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ausbl_test`   FOREIGN KEY (`test_id`)    REFERENCES `tests` (`id`)   ON DELETE CASCADE,
    CONSTRAINT `fk_ausbl_user`   FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
