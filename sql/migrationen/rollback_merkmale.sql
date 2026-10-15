-- =====================================================================
-- Rollback zu migration_2026_10_19_merkmale.sql
--
-- Entfernt Merkmale, Werte, Rassen-Merkmale, Textabschnitte und
-- breeds.daten_stand. Die alten Spalten (breed_sizes, Tags,
-- beschreibung …) sind unverändert, die Seiten lesen nach dem Zurücksetzen
-- des Codes wieder diese. Was nur im neuen Modell eingetragen wurde, geht
-- verloren.
-- Idempotent.
-- =====================================================================

DROP TABLE IF EXISTS `rasse_texte`;
DROP TABLE IF EXISTS `rasse_merkmale`;
DROP TABLE IF EXISTS `merkmal_werte`;
DROP TABLE IF EXISTS `merkmale`;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'daten_stand'), 'ALTER TABLE `breeds` DROP COLUMN `daten_stand`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
