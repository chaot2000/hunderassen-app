-- =====================================================================
-- Migration 2026-10-26: Wikidata-Zuordnung der Rassen
--
-- breeds.wikidata_id: zugeordneter Wikidata-Eintrag (z. B. "Q21080"),
-- Quelle der Bildkandidaten aus Wikimedia Commons in admin_rassenbild.php.
-- breeds.wikipedia_de: Titel des deutschen Wikipedia-Artikels, verlinkt
-- auf der Rassenseite.
-- breeds.bild_quelle wird auf 255 Zeichen verlängert, Dateinamen bei
-- Commons sind oft lang ("Wikimedia:<Dateiname>").
--
-- Idempotent, läuft auf MariaDB 10.11 und MySQL 8.x, auch im
-- SQL-Fenster von phpMyAdmin.
-- Zurück: sql/migrationen/rollback_wikidata.sql
-- =====================================================================

SET NAMES utf8mb4;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'wikidata_id'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `wikidata_id` VARCHAR(20) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `thedogapi_stand`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'wikipedia_de'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `wikipedia_de` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `wikidata_id`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'bild_quelle' AND CHARACTER_MAXIMUM_LENGTH < 255), 'ALTER TABLE `breeds` MODIFY COLUMN `bild_quelle` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
