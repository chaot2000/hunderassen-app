-- =====================================================================
-- Rollback zu migration_2026_10_20_merkmal_filter.sql
--
-- Entfernt merkmale.filter_verknuepfung. Der Katalog-Filter braucht die
-- Spalte, also vorher die Dateien auf den Stand davor zurücksetzen.
-- Idempotent, ein zweiter Lauf ändert nichts.
-- =====================================================================

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'merkmale' AND COLUMN_NAME = 'filter_verknuepfung'), 'ALTER TABLE `merkmale` DROP COLUMN `filter_verknuepfung`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
