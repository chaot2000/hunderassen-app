-- =====================================================================
-- Rollback zu migration_2026_10_22_test_abschnitte.sql
--
-- Entfernt test_aufgaben.abschnitt. Die Abschnitte gehen dabei verloren,
-- die Aufgaben selbst bleiben. Vorher die Dateien auf den Stand davor
-- zurücksetzen. Idempotent, ein zweiter Lauf ändert nichts.
-- =====================================================================

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'test_aufgaben' AND COLUMN_NAME = 'abschnitt'), 'ALTER TABLE `test_aufgaben` DROP COLUMN `abschnitt`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
