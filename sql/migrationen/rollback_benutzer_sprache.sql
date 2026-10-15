-- =====================================================================
-- Rollback zu migration_2026_10_18_benutzer_sprache.sql
--
-- Entfernt users.sprache. Danach ist die Oberfläche für alle deutsch.
-- Idempotent, ein zweiter Lauf ändert nichts.
-- =====================================================================

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'users' AND COLUMN_NAME = 'sprache'), 'ALTER TABLE `users` DROP COLUMN `sprache`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
