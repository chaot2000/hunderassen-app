-- =====================================================================
-- Migration 2026-10-24: Rassenbilder zurückstellen
--
-- breeds.bild_zurueckgestellt: Zeitpunkt, seit dem eine Rasse in
-- admin_rassenbilder.php nicht mehr angeboten wird, weil kein passendes
-- Bild dabei war. NULL heißt: wird angeboten. In der Ansicht
-- "Zurückgestellt" lässt sich das jederzeit wieder aufheben, etwa um
-- nach neuen Bildern bei TheDogAPI zu sehen.
--
-- Idempotent, läuft auf MariaDB 10.11 und MySQL 8.x, auch im
-- SQL-Fenster von phpMyAdmin.
-- Zurück: sql/migrationen/rollback_bild_zurueckgestellt.sql
-- =====================================================================

SET NAMES utf8mb4;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'bild_zurueckgestellt'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `bild_zurueckgestellt` DATETIME NULL AFTER `bild_quelle`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
