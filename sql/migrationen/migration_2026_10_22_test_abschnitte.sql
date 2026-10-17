-- =====================================================================
-- Migration 2026-10-22: Abschnitte in Tests
--
-- test_aufgaben.abschnitt: optionale Zwischenüberschrift, unter der eine
-- Aufgabe steht (z. B. "Wesen und Bindung", "Sozialisierung"). Formular
-- und Durchführung zeigen sie als Überschrift, sobald sie wechselt.
-- Kommt mit dem Import der Excel-Tests (tools/excel_tests_umwandeln.py).
--
-- Bestehende Aufgaben bleiben ohne Abschnitt. Idempotent, läuft auf
-- MariaDB 10.11 und MySQL 8.x, auch im SQL-Fenster von phpMyAdmin.
-- Zurück: sql/migrationen/rollback_test_abschnitte.sql
-- =====================================================================

SET NAMES utf8mb4;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'test_aufgaben' AND COLUMN_NAME = 'abschnitt'), 'DO 0', 'ALTER TABLE `test_aufgaben` ADD COLUMN `abschnitt` VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `test_id`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
