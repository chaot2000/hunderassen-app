-- =====================================================================
-- Migration 2026-10-17: englische Rasseninhalte
--
-- Neue Felder in breeds:
--   name_en, temperament_en, zuchtzweck_en, beschreibung_en
-- Englisch kommt aus TheDogAPI, die deutschen Felder sind die
-- Übersetzung (Temperament per Wörterbuch, der Rest von Hand).
--
-- Bisher hat der TheDogAPI-Abgleich englische Werte direkt in
-- temperament und zuchtzweck geschrieben. Eine Oberfläche zum Eintragen
-- gab es nicht, alles dort ist also englisch. Diese Werte wandern nach
-- *_en, das deutsche Feld wird leer und später übersetzt.
--
-- Idempotent: verschoben wird nur, solange *_en noch leer ist.
-- Läuft auf MariaDB 10.11 und MySQL 8.x, auch im SQL-Fenster von
-- phpMyAdmin. Zurück: sql/migrationen/rollback_rassen_englisch.sql
-- =====================================================================

SET NAMES utf8mb4;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'name_en'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `name_en` VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `name`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'beschreibung_en'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `beschreibung_en` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `beschreibung_original`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'temperament_en'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `temperament_en` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `temperament`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'zuchtzweck_en'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `zuchtzweck_en` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `zuchtzweck`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Bisherige TheDogAPI-Werte sind englisch: verschieben.
UPDATE `breeds` SET `temperament_en` = `temperament`, `temperament` = NULL
 WHERE `temperament_en` IS NULL AND `temperament` IS NOT NULL AND `temperament` <> '';
UPDATE `breeds` SET `zuchtzweck_en` = `zuchtzweck`, `zuchtzweck` = NULL
 WHERE `zuchtzweck_en` IS NULL AND `zuchtzweck` IS NOT NULL AND `zuchtzweck` <> '';
