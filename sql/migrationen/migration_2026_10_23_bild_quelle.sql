-- =====================================================================
-- Migration 2026-10-23: Herkunft der Rassenbilder
--
-- breeds.bild_quelle: woher das Bild stammt, z. B. "TheDogAPI:M5cp3u9VBx".
-- NULL heißt eigenes Bild oder unbekannt. Gesetzt von
-- admin_rassenbilder.php und admin_thedogapi_apply.php, zurückgesetzt,
-- sobald jemand ein eigenes Bild hochlädt oder das Bild entfernt.
-- Damit lassen sich alle Bilder aus TheDogAPI später finden.
--
-- Bestehende Bilder bleiben ohne Quelle. Idempotent, läuft auf
-- MariaDB 10.11 und MySQL 8.x, auch im SQL-Fenster von phpMyAdmin.
-- Zurück: sql/migrationen/rollback_bild_quelle.sql
-- =====================================================================

SET NAMES utf8mb4;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'bild_quelle'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `bild_quelle` VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `bild_updated_at`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
