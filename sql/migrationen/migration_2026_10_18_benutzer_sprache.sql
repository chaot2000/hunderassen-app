-- =====================================================================
-- Migration 2026-10-18: Sprache je Benutzer
--
-- users.sprache 'de' | 'en', Standard 'de'. Steuert die Sprache der
-- Oberfläche (config/i18n.php, lang/*.php), umschaltbar im Kopf.
--
-- Idempotent, läuft auf MariaDB 10.11 und MySQL 8.x, auch im SQL-Fenster
-- von phpMyAdmin. Zurück: sql/migrationen/rollback_benutzer_sprache.sql
-- =====================================================================

SET NAMES utf8mb4;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'sprache'), 'DO 0', 'ALTER TABLE `users` ADD COLUMN `sprache` ENUM(''de'', ''en'') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''de'' AFTER `can_manage_tests`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
