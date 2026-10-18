-- =====================================================================
-- Rollback zu migration_2026_10_25_bildnachweis.sql
--
-- Entfernt bild_urheber, bild_lizenz und bild_lizenz_url aus breeds.
-- Die Bilder bleiben, der Nachweis geht verloren. Vorher die Dateien auf
-- den Stand davor zurücksetzen. Idempotent.
-- =====================================================================

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'bild_lizenz_url'), 'ALTER TABLE `breeds` DROP COLUMN `bild_lizenz_url`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'bild_lizenz'), 'ALTER TABLE `breeds` DROP COLUMN `bild_lizenz`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'bild_urheber'), 'ALTER TABLE `breeds` DROP COLUMN `bild_urheber`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
