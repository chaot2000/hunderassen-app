-- =====================================================================
-- Rollback zu migration_2026_10_27_groessenklasse_berechnet.sql
--
-- Berechnete Größenklassen bleiben stehen, gelten danach als
-- übernommene Altwerte (quelle 'alt'). Erst danach wird der Wert
-- 'berechnet' aus der Spalte entfernt.
--
-- Idempotent. Vorher die Dateien des Pakets zurückspielen.
-- =====================================================================

SET NAMES utf8mb4;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rasse_merkmale' AND COLUMN_NAME = 'quelle' AND COLUMN_TYPE LIKE '%''berechnet''%'), 'UPDATE `rasse_merkmale` SET `quelle` = ''alt'' WHERE `quelle` = ''berechnet''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rasse_merkmale' AND COLUMN_NAME = 'quelle' AND COLUMN_TYPE LIKE '%''berechnet''%'), 'ALTER TABLE `rasse_merkmale` MODIFY COLUMN `quelle` ENUM(''alt'', ''hand'', ''thedogapi'', ''vdh_text'') NOT NULL DEFAULT ''hand''', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
