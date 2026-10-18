-- =====================================================================
-- Rollback zu migration_2026_10_23_bild_quelle.sql
--
-- Entfernt breeds.bild_quelle. Die Bilder selbst bleiben, nur die
-- Herkunftsangabe geht verloren. Vorher die Dateien auf den Stand davor
-- zurücksetzen. Idempotent, ein zweiter Lauf ändert nichts.
-- =====================================================================

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'bild_quelle'), 'ALTER TABLE `breeds` DROP COLUMN `bild_quelle`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
