-- =====================================================================
-- migration_2026_07_04_mehrfachhalter_und_zuechter_erweitert.sql
--
-- 1) Mehrere Halter pro Hund ("Familie"): neue Pivot-Tabelle
--    `dog_halter` (n:m zwischen dogs und halter) ersetzt die bisherige
--    1:1-Spalte `dogs.halter_id`. Bestehende Zuordnungen werden
--    automatisch übernommen. `dogs.halter_id` bleibt non-destruktiv
--    als Altlast stehen (wird vom Code ab sofort nicht mehr genutzt).
--
-- 2) Züchter-Felder erweitert:
--    - `name` -> `zuchtname` umbenannt (per CHANGE COLUMN, da es sich
--      um eine Umbenennung und nicht nur eine neue Spalte handelt)
--    - neu: `ansprechpartner_vorname`, `ansprechpartner_nachname`,
--      `webseite`
--    (telefon, email, adresse, notizen existieren bereits)
--
-- Idempotent über INFORMATION_SCHEMA-Prüfung + PREPARE/EXECUTE, wie
-- in den vorherigen Migrationen dieses Projekts.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1) dog_halter Pivot-Tabelle
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `dog_halter` (
    `dog_id`    INT UNSIGNED NOT NULL,
    `halter_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`dog_id`, `halter_id`),
    KEY `idx_dog_halter_halter` (`halter_id`),
    CONSTRAINT `fk_doghalter_dog`    FOREIGN KEY (`dog_id`)    REFERENCES `dogs` (`id`)   ON DELETE CASCADE,
    CONSTRAINT `fk_doghalter_halter` FOREIGN KEY (`halter_id`) REFERENCES `halter` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Bestehende 1:1-Zuordnungen aus dogs.halter_id übernehmen (nur falls
-- die Spalte überhaupt noch existiert und befüllt ist).
SET @has_halter_id_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dogs' AND COLUMN_NAME = 'halter_id'
);
SET @sql_migrate_halter := IF(@has_halter_id_col > 0,
    'INSERT IGNORE INTO dog_halter (dog_id, halter_id)
     SELECT id, halter_id FROM dogs WHERE halter_id IS NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_migrate_halter; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 2) Züchter-Felder erweitern
-- ---------------------------------------------------------------------

-- name -> zuchtname umbenennen (nur falls 'name' noch existiert und
-- 'zuchtname' noch nicht — sonst wurde die Migration schon ausgeführt)
SET @has_name_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zuechter' AND COLUMN_NAME = 'name'
);
SET @has_zuchtname_col := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zuechter' AND COLUMN_NAME = 'zuchtname'
);
SET @sql_rename_zuchtname := IF(@has_name_col > 0 AND @has_zuchtname_col = 0,
    'ALTER TABLE `zuechter` CHANGE COLUMN `name` `zuchtname` VARCHAR(150) NOT NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_rename_zuchtname; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_vorname := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zuechter' AND COLUMN_NAME = 'ansprechpartner_vorname'
);
SET @sql_vorname := IF(@has_vorname = 0,
    'ALTER TABLE `zuechter` ADD COLUMN `ansprechpartner_vorname` VARCHAR(100) NULL AFTER `zuchtname`',
    'SELECT 1'
);
PREPARE stmt FROM @sql_vorname; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_nachname := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zuechter' AND COLUMN_NAME = 'ansprechpartner_nachname'
);
SET @sql_nachname := IF(@has_nachname = 0,
    'ALTER TABLE `zuechter` ADD COLUMN `ansprechpartner_nachname` VARCHAR(100) NULL AFTER `ansprechpartner_vorname`',
    'SELECT 1'
);
PREPARE stmt FROM @sql_nachname; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_webseite := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zuechter' AND COLUMN_NAME = 'webseite'
);
SET @sql_webseite := IF(@has_webseite = 0,
    'ALTER TABLE `zuechter` ADD COLUMN `webseite` VARCHAR(255) NULL AFTER `email`',
    'SELECT 1'
);
PREPARE stmt FROM @sql_webseite; EXECUTE stmt; DEALLOCATE PREPARE stmt;
