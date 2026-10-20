-- =====================================================================
-- Migration 2026-10-27: Größenklasse aus der Schulterhöhe
--
-- rasse_merkmale.quelle bekommt den Wert 'berechnet'. Die Größenklasse
-- ergibt sich aus der Schulterhöhe (models/Groessenklasse.php), außer
-- sie wurde unter "Rasse bearbeiten" von Hand gesetzt (quelle 'hand').
-- Vorhandene Daten ändern sich nicht. Berechnet wird erst über den Knopf
-- auf der Seite "Merkmale" bzw. beim Speichern einer Rasse.
--
-- Idempotent, läuft auf MariaDB 10.11 und MySQL 8.x, auch im
-- SQL-Fenster von phpMyAdmin.
-- Zurück: sql/migrationen/rollback_groessenklasse_berechnet.sql
-- =====================================================================

SET NAMES utf8mb4;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'rasse_merkmale' AND COLUMN_NAME = 'quelle' AND COLUMN_TYPE LIKE '%''berechnet''%'), 'DO 0', 'ALTER TABLE `rasse_merkmale` MODIFY COLUMN `quelle` ENUM(''alt'', ''hand'', ''thedogapi'', ''vdh_text'', ''berechnet'') NOT NULL DEFAULT ''hand''');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
