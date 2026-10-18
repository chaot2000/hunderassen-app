-- =====================================================================
-- Rollback zu migration_2026_10_26_wikidata.sql
--
-- Entfernt wikidata_id und wikipedia_de und kürzt bild_quelle wieder auf
-- 100 Zeichen. Längere Herkunftsangaben werden dabei abgeschnitten, das
-- Bild bleibt. Vorher die Dateien auf den Stand davor zurücksetzen.
-- Idempotent.
-- =====================================================================

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'wikipedia_de'), 'ALTER TABLE `breeds` DROP COLUMN `wikipedia_de`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'wikidata_id'), 'ALTER TABLE `breeds` DROP COLUMN `wikidata_id`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE breeds SET bild_quelle = LEFT(bild_quelle, 100) WHERE CHAR_LENGTH(bild_quelle) > 100;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'bild_quelle' AND CHARACTER_MAXIMUM_LENGTH > 100), 'ALTER TABLE `breeds` MODIFY COLUMN `bild_quelle` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
