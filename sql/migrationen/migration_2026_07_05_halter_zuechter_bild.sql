-- =====================================================================
-- migration_2026_07_05_halter_zuechter_bild.sql
--
-- Ergänzt Halter und Züchter um dieselben Bild-Spalten, die dogs/breeds
-- bereits haben (bild_blob, bild_mime, bild_updated_at) — Verwaltung
-- und Verarbeitung laufen über die bereits vorhandene, generische
-- Klasse models/BildVerarbeitung.php (kein neuer Code für Validierung/
-- Verkleinerung nötig).
--
-- Idempotent über INFORMATION_SCHEMA-Prüfung + PREPARE/EXECUTE, wie in
-- den vorherigen Migrationen dieses Projekts.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- halter
-- ---------------------------------------------------------------------
SET @has_halter_bild_blob := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'halter' AND COLUMN_NAME = 'bild_blob'
);
SET @sql_halter_bild_blob := IF(@has_halter_bild_blob = 0,
    'ALTER TABLE `halter` ADD COLUMN `bild_blob` LONGBLOB NULL AFTER `notizen`',
    'SELECT 1'
);
PREPARE stmt FROM @sql_halter_bild_blob; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_halter_bild_mime := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'halter' AND COLUMN_NAME = 'bild_mime'
);
SET @sql_halter_bild_mime := IF(@has_halter_bild_mime = 0,
    'ALTER TABLE `halter` ADD COLUMN `bild_mime` VARCHAR(50) NULL AFTER `bild_blob`',
    'SELECT 1'
);
PREPARE stmt FROM @sql_halter_bild_mime; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_halter_bild_updated := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'halter' AND COLUMN_NAME = 'bild_updated_at'
);
SET @sql_halter_bild_updated := IF(@has_halter_bild_updated = 0,
    'ALTER TABLE `halter` ADD COLUMN `bild_updated_at` DATETIME NULL AFTER `bild_mime`',
    'SELECT 1'
);
PREPARE stmt FROM @sql_halter_bild_updated; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- zuechter
-- ---------------------------------------------------------------------
SET @has_zuechter_bild_blob := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zuechter' AND COLUMN_NAME = 'bild_blob'
);
SET @sql_zuechter_bild_blob := IF(@has_zuechter_bild_blob = 0,
    'ALTER TABLE `zuechter` ADD COLUMN `bild_blob` LONGBLOB NULL AFTER `notizen`',
    'SELECT 1'
);
PREPARE stmt FROM @sql_zuechter_bild_blob; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_zuechter_bild_mime := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zuechter' AND COLUMN_NAME = 'bild_mime'
);
SET @sql_zuechter_bild_mime := IF(@has_zuechter_bild_mime = 0,
    'ALTER TABLE `zuechter` ADD COLUMN `bild_mime` VARCHAR(50) NULL AFTER `bild_blob`',
    'SELECT 1'
);
PREPARE stmt FROM @sql_zuechter_bild_mime; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_zuechter_bild_updated := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'zuechter' AND COLUMN_NAME = 'bild_updated_at'
);
SET @sql_zuechter_bild_updated := IF(@has_zuechter_bild_updated = 0,
    'ALTER TABLE `zuechter` ADD COLUMN `bild_updated_at` DATETIME NULL AFTER `bild_mime`',
    'SELECT 1'
);
PREPARE stmt FROM @sql_zuechter_bild_updated; EXECUTE stmt; DEALLOCATE PREPARE stmt;
