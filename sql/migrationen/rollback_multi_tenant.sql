-- =====================================================================
-- Rollback der Mandanten-Migration (migration_2026_07_10_multi_tenant.sql)
--
-- WARNUNG: Entfernt die Tabelle tenants und alle tenant_id-Spalten.
-- Vorher ein Backup ziehen.
--
-- Bricht ab, BEVOR etwas gelöscht wird, wenn es Zeilen in einem
-- anderen Mandanten als 1 gibt. Sie würden sonst unbemerkt mit
-- Mandant 1 zusammenfallen, und doppelte Benutzer- oder Testnamen
-- würden das Wiederherstellen von uq_username/uq_test_name verhindern.
-- Solche Daten vorher von Hand verschieben oder löschen.
-- Der Abbruch erscheint als Fehler "Table '...ABBRUCH_...' doesn't exist".
--
-- Idempotent wie die Migration: ein zweiter Lauf ändert nichts.
-- =====================================================================

SET NAMES utf8mb4;

-- 1. Sicherheitsprüfungen (nur solange tenant_id noch existiert)
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tenant_id'),
    'SET @fremd := (SELECT COUNT(*) FROM `users` WHERE `tenant_id` <> 1)', 'SET @fremd := 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM DUAL WHERE @fremd > 0), 'SELECT * FROM `ABBRUCH_users_hat_Daten_in_anderen_Mandanten`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dogs' AND COLUMN_NAME = 'tenant_id'),
    'SET @fremd := (SELECT COUNT(*) FROM `dogs` WHERE `tenant_id` <> 1)', 'SET @fremd := 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM DUAL WHERE @fremd > 0), 'SELECT * FROM `ABBRUCH_dogs_hat_Daten_in_anderen_Mandanten`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'halter' AND COLUMN_NAME = 'tenant_id'),
    'SET @fremd := (SELECT COUNT(*) FROM `halter` WHERE `tenant_id` <> 1)', 'SET @fremd := 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM DUAL WHERE @fremd > 0), 'SELECT * FROM `ABBRUCH_halter_hat_Daten_in_anderen_Mandanten`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zuechter' AND COLUMN_NAME = 'tenant_id'),
    'SET @fremd := (SELECT COUNT(*) FROM `zuechter` WHERE `tenant_id` <> 1)', 'SET @fremd := 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM DUAL WHERE @fremd > 0), 'SELECT * FROM `ABBRUCH_zuechter_hat_Daten_in_anderen_Mandanten`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND COLUMN_NAME = 'tenant_id'),
    'SET @fremd := (SELECT COUNT(*) FROM `tests` WHERE `tenant_id` <> 1)', 'SET @fremd := 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM DUAL WHERE @fremd > 0), 'SELECT * FROM `ABBRUCH_tests_hat_Daten_in_anderen_Mandanten`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 2. Globale Eindeutigkeit wiederherstellen (vor dem Entfernen der neuen Schlüssel)
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_username'), 'DO 0', 'ALTER TABLE `users` ADD UNIQUE KEY `uq_username` (`username`)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND INDEX_NAME = 'uq_test_name'), 'DO 0', 'ALTER TABLE `tests` ADD UNIQUE KEY `uq_test_name` (`name`)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'uq_tenant_username'), 'ALTER TABLE `users` DROP INDEX `uq_tenant_username`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND INDEX_NAME = 'uq_tenant_test_name'), 'ALTER TABLE `tests` DROP INDEX `uq_tenant_test_name`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 3. Fremdschlüssel, Index und Spalte tenant_id entfernen
-- users
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND CONSTRAINT_NAME = 'fk_users_tenant' AND CONSTRAINT_TYPE = 'FOREIGN KEY'), 'ALTER TABLE `users` DROP FOREIGN KEY `fk_users_tenant`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND INDEX_NAME = 'idx_tenant_id'), 'ALTER TABLE `users` DROP INDEX `idx_tenant_id`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'tenant_id'), 'ALTER TABLE `users` DROP COLUMN `tenant_id`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- dogs
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'dogs' AND CONSTRAINT_NAME = 'fk_dogs_tenant' AND CONSTRAINT_TYPE = 'FOREIGN KEY'), 'ALTER TABLE `dogs` DROP FOREIGN KEY `fk_dogs_tenant`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dogs' AND INDEX_NAME = 'idx_tenant_id'), 'ALTER TABLE `dogs` DROP INDEX `idx_tenant_id`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dogs' AND COLUMN_NAME = 'tenant_id'), 'ALTER TABLE `dogs` DROP COLUMN `tenant_id`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- halter
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'halter' AND CONSTRAINT_NAME = 'fk_halter_tenant' AND CONSTRAINT_TYPE = 'FOREIGN KEY'), 'ALTER TABLE `halter` DROP FOREIGN KEY `fk_halter_tenant`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'halter' AND INDEX_NAME = 'idx_tenant_id'), 'ALTER TABLE `halter` DROP INDEX `idx_tenant_id`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'halter' AND COLUMN_NAME = 'tenant_id'), 'ALTER TABLE `halter` DROP COLUMN `tenant_id`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- zuechter
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'zuechter' AND CONSTRAINT_NAME = 'fk_zuechter_tenant' AND CONSTRAINT_TYPE = 'FOREIGN KEY'), 'ALTER TABLE `zuechter` DROP FOREIGN KEY `fk_zuechter_tenant`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zuechter' AND INDEX_NAME = 'idx_tenant_id'), 'ALTER TABLE `zuechter` DROP INDEX `idx_tenant_id`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zuechter' AND COLUMN_NAME = 'tenant_id'), 'ALTER TABLE `zuechter` DROP COLUMN `tenant_id`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- tests
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.TABLE_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND CONSTRAINT_NAME = 'fk_tests_tenant' AND CONSTRAINT_TYPE = 'FOREIGN KEY'), 'ALTER TABLE `tests` DROP FOREIGN KEY `fk_tests_tenant`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND INDEX_NAME = 'idx_tenant_id'), 'ALTER TABLE `tests` DROP INDEX `idx_tenant_id`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND COLUMN_NAME = 'tenant_id'), 'ALTER TABLE `tests` DROP COLUMN `tenant_id`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
-- login_attempts
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND INDEX_NAME = 'idx_tenant_time'), 'ALTER TABLE `login_attempts` DROP INDEX `idx_tenant_time`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'login_attempts' AND COLUMN_NAME = 'tenant_id'), 'ALTER TABLE `login_attempts` DROP COLUMN `tenant_id`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- 4. Super-Admin-Spalte und Tabelle tenants
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'is_super_admin'), 'ALTER TABLE `users` DROP COLUMN `is_super_admin`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
DROP TABLE IF EXISTS `tenants`;
