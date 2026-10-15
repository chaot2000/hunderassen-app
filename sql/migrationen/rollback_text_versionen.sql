-- =====================================================================
-- Rollback zu migration_2026_10_15_text_versionen.sql
--
-- ACHTUNG: Löscht alle gespeicherten Textversionen und die Spalte
-- breeds.beschreibung_original. Vorher exportieren, wenn die Versionen
-- noch gebraucht werden:
--   SELECT * FROM content_revisions;  -> phpMyAdmin "Exportieren"
--
-- Idempotent, ein zweiter Lauf ändert nichts.
-- =====================================================================

DROP TABLE IF EXISTS `content_revisions`;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'beschreibung_original'), 'ALTER TABLE `breeds` DROP COLUMN `beschreibung_original`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
