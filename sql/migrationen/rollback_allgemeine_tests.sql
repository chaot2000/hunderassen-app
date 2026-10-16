-- =====================================================================
-- Rollback zu migration_2026_10_21_allgemeine_tests.sql
--
-- Geht nur, solange es keine allgemeinen Tests gibt (tenant_id NULL).
-- Sonst bricht das Skript beim ersten Schritt mit dem Fehler
-- "Table '...rollback_abgebrochen_es_gibt_allgemeine_tests' doesn't exist"
-- ab und ändert nichts. Allgemeine Tests vorher in der App zurück in
-- eine Organisation holen oder löschen.
--
-- Vorher die Dateien auf den Stand vor der Migration zurücksetzen.
-- Idempotent, ein zweiter Lauf ändert nichts.
-- =====================================================================

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND COLUMN_NAME = 'tenant_id' AND IS_NULLABLE = 'YES')
               AND EXISTS(SELECT 1 FROM `tests` WHERE `tenant_id` IS NULL),
               'SELECT 1 FROM `rollback_abgebrochen_es_gibt_allgemeine_tests`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

DROP TABLE IF EXISTS `test_ausblendungen`;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND INDEX_NAME = 'uq_tenant_test_name'), 'DO 0', 'ALTER TABLE `tests` ADD UNIQUE KEY `uq_tenant_test_name` (`tenant_id`, `name`)');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND INDEX_NAME = 'uq_scope_test_name'), 'ALTER TABLE `tests` DROP INDEX `uq_scope_test_name`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND COLUMN_NAME = 'scope_id'), 'ALTER TABLE `tests` DROP COLUMN `scope_id`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND COLUMN_NAME = 'tenant_id' AND IS_NULLABLE = 'YES'), 'ALTER TABLE `tests` MODIFY `tenant_id` INT UNSIGNED NOT NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
