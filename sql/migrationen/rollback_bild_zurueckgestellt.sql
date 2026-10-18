-- =====================================================================
-- Rollback zu migration_2026_10_24_bild_zurueckgestellt.sql
--
-- Entfernt breeds.bild_zurueckgestellt. Zurückgestellte Rassen werden
-- danach wieder angeboten. Vorher die Dateien auf den Stand davor
-- zurücksetzen. Idempotent, ein zweiter Lauf ändert nichts.
-- =====================================================================

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'bild_zurueckgestellt'), 'ALTER TABLE `breeds` DROP COLUMN `bild_zurueckgestellt`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
