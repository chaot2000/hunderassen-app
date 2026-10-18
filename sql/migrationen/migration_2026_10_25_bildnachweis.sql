-- =====================================================================
-- Migration 2026-10-25: Bildnachweis für Rassenbilder
--
-- breeds.bild_urheber, bild_lizenz, bild_lizenz_url: wer das Bild
-- gemacht hat und unter welcher Lizenz es steht. Gesetzt beim Übernehmen
-- aus Wikimedia Commons und beim Hochladen (Urheber freiwillig) in
-- admin_rassenbild.php. Bei CC BY und CC BY-SA muss der Nachweis beim
-- Bild stehen. Ein Upload über "Rasse bearbeiten" oder das Entfernen des
-- Bildes leert die Felder wie bild_quelle.
--
-- Idempotent, läuft auf MariaDB 10.11 und MySQL 8.x, auch im
-- SQL-Fenster von phpMyAdmin.
-- Zurück: sql/migrationen/rollback_bildnachweis.sql
-- =====================================================================

SET NAMES utf8mb4;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'bild_urheber'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `bild_urheber` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `bild_quelle`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'bild_lizenz'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `bild_lizenz` VARCHAR(50) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `bild_urheber`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'bild_lizenz_url'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `bild_lizenz_url` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `bild_lizenz`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
