-- =====================================================================
-- Migration 2026-10-20: Verknüpfung der Katalog-Filter je Merkmal
--
-- merkmale.filter_verknuepfung: 'und' = Rasse muss alle gewählten Werte
-- haben (Wesen, Eignung), 'oder' = einer reicht (Größenklasse, Farben).
-- In der Merkmal-Verwaltung änderbar (admin_merkmale.php).
--
-- Idempotent, läuft auf MariaDB 10.11 und MySQL 8.x, auch im SQL-Fenster
-- von phpMyAdmin. Zurück: sql/migrationen/rollback_merkmal_filter.sql
-- =====================================================================

SET NAMES utf8mb4;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'merkmale' AND COLUMN_NAME = 'filter_verknuepfung'), 'DO 0', 'ALTER TABLE `merkmale` ADD COLUMN `filter_verknuepfung` ENUM(''und'', ''oder'') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''und'' AFTER `filterbar`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

UPDATE `merkmale` SET `filter_verknuepfung` = 'oder'
WHERE `schluessel` IN ('groessenklasse', 'farben', 'fellart', 'verbreitung', 'arbeitspruefung', 'pflegeaufwand', 'bewegungsbedarf', 'beschaeftigungsbedarf', 'ursprungsland');
