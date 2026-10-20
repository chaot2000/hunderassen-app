-- =====================================================================
-- Migration 2026-10-28: Rassenbilder als geprüft markieren
--
-- breeds.bild_geprueft: wann das Bild zuletzt als passend bestätigt
-- wurde, NULL = ungeprüft. admin_rassenbilder.php zeigt ungeprüfte
-- Bilder in einem eigenen Reiter. Ein Bild, das in der Bildauswahl oder
-- unter "Rasse bearbeiten" gesetzt wird, gilt als geprüft, ein neues aus
-- dem TheDogAPI-Abgleich nicht. Vorhandene Bilder sind danach ungeprüft.
--
-- Idempotent, läuft auf MariaDB 10.11 und MySQL 8.x, auch im
-- SQL-Fenster von phpMyAdmin.
-- Zurück: sql/migrationen/rollback_bild_geprueft.sql
-- =====================================================================

SET NAMES utf8mb4;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'bild_geprueft'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `bild_geprueft` DATETIME NULL AFTER `bild_zurueckgestellt`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
