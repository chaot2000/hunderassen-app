-- =====================================================================
-- Migration 2026-10-16: strukturierte Rassendaten
--
-- Neue Felder in breeds für die Rassen-Detailseite:
--   fci_sektion, fci_sektion_name, arbeitspruefung  (aus dem FCI-Kopf)
--   quelle_url, quelle_stand                         (Herkunft der Texte)
--   gliederung  'keine' | 'automatisch' | 'geprueft'
-- content_revisions.anlass bekommt 'umstrukturierung'.
--
-- Die Migration legt nur Spalten an. Gefüllt werden sie über die Seite
-- admin_rassentexte.php (Super-Admin), mit Vorschau und Versionen.
--
-- Idempotent, läuft auf MariaDB 10.11 und MySQL 8.x, auch im SQL-Fenster
-- von phpMyAdmin. Zurück: sql/migrationen/rollback_rassen_struktur.sql
-- =====================================================================

SET NAMES utf8mb4;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'fci_sektion'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `fci_sektion` VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `fci_nr`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'fci_sektion_name'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `fci_sektion_name` VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `fci_sektion`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'arbeitspruefung'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `arbeitspruefung` ENUM(''mit'', ''ohne'', ''fakultativ'') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `fci_sektion_name`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'quelle_url'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `quelle_url` VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `zuchtzweck`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'quelle_stand'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `quelle_stand` DATE NULL AFTER `quelle_url`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'gliederung'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `gliederung` ENUM(''keine'', ''automatisch'', ''geprueft'') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT ''keine'' AFTER `quelle_stand`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Anlass 'umstrukturierung' (MODIFY ist ohne Prüfung idempotent)
ALTER TABLE `content_revisions`
    MODIFY COLUMN `anlass` ENUM('bearbeitung', 'import', 'loeschung', 'wiederherstellung', 'umstrukturierung') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL;
