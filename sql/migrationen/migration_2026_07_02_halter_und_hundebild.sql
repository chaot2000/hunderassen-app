-- =====================================================================
-- migration_2026_07_02_halter_und_hundebild.sql
--
-- 1) Eigenständige Halterverwaltung: bisher war "Halter" nur ein
--    nullable FK auf `users` (siehe migration_2026_07_02_dogs.sql).
--    Jetzt: eigene Tabelle `halter` mit eigenen Kontaktdaten, da ein
--    Halter kein App-Benutzer sein muss (und umgekehrt).
--    `dogs.halter_user_id` bleibt vorerst unangetastet in der DB
--    stehen (kein Datenverlust, falls etwas schiefgeht) — Code nutzt
--    ab jetzt aber `dogs.halter_id`. Bestehende Zuordnungen werden
--    automatisch in `halter`-Datensätze überführt (Schritt 3).
--
-- 2) Fotospalten für Hunde, analog zu breeds.bild_blob/bild_mime/
--    bild_updated_at.
--
-- Idempotent: ALTER TABLE ... ADD COLUMN über INFORMATION_SCHEMA-
-- Prüfung + PREPARE/EXECUTE (kein natives "IF NOT EXISTS" bei
-- MySQL-ADD COLUMN, siehe frühere Migrationen in diesem Projekt).
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1) Tabelle: halter
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `halter` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(150) NOT NULL,
    `telefon`     VARCHAR(50)  NULL,
    `email`       VARCHAR(150) NULL,
    `adresse`     VARCHAR(255) NULL,
    `notizen`     TEXT NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`  INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_halter_name` (`name`),
    CONSTRAINT `fk_halter_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- 2) dogs.halter_id (neue Verknüpfung) + Fotospalten
-- ---------------------------------------------------------------------
SET @has_halter_id := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dogs' AND COLUMN_NAME = 'halter_id'
);
SET @sql_halter_id := IF(@has_halter_id = 0,
    'ALTER TABLE `dogs` ADD COLUMN `halter_id` INT UNSIGNED NULL AFTER `halter_user_id`',
    'SELECT 1'
);
PREPARE stmt FROM @sql_halter_id; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_bild_blob := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dogs' AND COLUMN_NAME = 'bild_blob'
);
SET @sql_bild_blob := IF(@has_bild_blob = 0,
    'ALTER TABLE `dogs` ADD COLUMN `bild_blob` LONGBLOB NULL AFTER `farbe`',
    'SELECT 1'
);
PREPARE stmt FROM @sql_bild_blob; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_bild_mime := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dogs' AND COLUMN_NAME = 'bild_mime'
);
SET @sql_bild_mime := IF(@has_bild_mime = 0,
    'ALTER TABLE `dogs` ADD COLUMN `bild_mime` VARCHAR(50) NULL AFTER `bild_blob`',
    'SELECT 1'
);
PREPARE stmt FROM @sql_bild_mime; EXECUTE stmt; DEALLOCATE PREPARE stmt;

SET @has_bild_updated := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dogs' AND COLUMN_NAME = 'bild_updated_at'
);
SET @sql_bild_updated := IF(@has_bild_updated = 0,
    'ALTER TABLE `dogs` ADD COLUMN `bild_updated_at` DATETIME NULL AFTER `bild_mime`',
    'SELECT 1'
);
PREPARE stmt FROM @sql_bild_updated; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Fremdschlüssel für halter_id nur anlegen, falls noch nicht vorhanden.
SET @has_fk_halter := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.TABLE_CONSTRAINTS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'dogs' AND CONSTRAINT_NAME = 'fk_dogs_halter_id'
);
SET @sql_fk_halter := IF(@has_fk_halter = 0,
    'ALTER TABLE `dogs` ADD CONSTRAINT `fk_dogs_halter_id` FOREIGN KEY (`halter_id`) REFERENCES `halter` (`id`) ON DELETE SET NULL',
    'SELECT 1'
);
PREPARE stmt FROM @sql_fk_halter; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- ---------------------------------------------------------------------
-- 3) Datenübernahme: bisherige dogs.halter_user_id -> echter
--    halter-Datensatz (Name = bisheriger Username), sofern noch keiner
--    mit diesem Namen existiert. Danach dogs.halter_id setzen.
--    dogs.halter_user_id bleibt unangetastet (Altlast, kann später
--    manuell per eigenem ALTER TABLE DROP COLUMN entfernt werden,
--    sobald sicher ist, dass sie nirgends mehr gebraucht wird).
-- ---------------------------------------------------------------------
INSERT INTO `halter` (`name`, `created_at`)
SELECT DISTINCT u.username, NOW()
FROM `users` u
INNER JOIN `dogs` d ON d.halter_user_id = u.id
WHERE NOT EXISTS (SELECT 1 FROM `halter` h WHERE h.name = u.username);

UPDATE `dogs` d
INNER JOIN `users` u ON u.id = d.halter_user_id
INNER JOIN `halter` h ON h.name = u.username
SET d.halter_id = h.id
WHERE d.halter_id IS NULL AND d.halter_user_id IS NOT NULL;
