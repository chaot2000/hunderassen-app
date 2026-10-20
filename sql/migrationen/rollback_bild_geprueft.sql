-- =====================================================================
-- Rollback zu migration_2026_10_28_bild_geprueft.sql
--
-- Entfernt breeds.bild_geprueft, die Prüfvermerke gehen verloren, die
-- Bilder bleiben. Vorher die Dateien auf den Stand davor zurücksetzen.
-- Idempotent, ein zweiter Lauf ändert nichts.
-- =====================================================================

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'bild_geprueft'), 'ALTER TABLE `breeds` DROP COLUMN `bild_geprueft`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
