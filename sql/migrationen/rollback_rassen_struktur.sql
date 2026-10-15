-- =====================================================================
-- Rollback zu migration_2026_10_16_rassen_struktur.sql
--
-- VORHER in admin_rassentexte.php "Umstellung rückgängig machen"
-- ausführen, sonst bleiben die umgestellten Texte in `beschreibung`
-- stehen (ohne Felder für Sektion und Arbeitsprüfung). Versionen mit
-- Anlass 'umstrukturierung' werden hier auf 'bearbeitung' gesetzt, damit
-- der alte ENUM wieder passt; der Inhalt der Versionen bleibt.
--
-- Idempotent, ein zweiter Lauf ändert nichts.
-- =====================================================================

UPDATE `content_revisions` SET `anlass` = 'bearbeitung' WHERE `anlass` = 'umstrukturierung';
ALTER TABLE `content_revisions`
    MODIFY COLUMN `anlass` ENUM('bearbeitung', 'import', 'loeschung', 'wiederherstellung') NOT NULL;

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'gliederung'), 'ALTER TABLE `breeds` DROP COLUMN `gliederung`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'quelle_stand'), 'ALTER TABLE `breeds` DROP COLUMN `quelle_stand`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'quelle_url'), 'ALTER TABLE `breeds` DROP COLUMN `quelle_url`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'arbeitspruefung'), 'ALTER TABLE `breeds` DROP COLUMN `arbeitspruefung`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'fci_sektion_name'), 'ALTER TABLE `breeds` DROP COLUMN `fci_sektion_name`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'fci_sektion'), 'ALTER TABLE `breeds` DROP COLUMN `fci_sektion`', 'DO 0');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;
