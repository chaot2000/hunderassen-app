-- =====================================================================
-- migration_2026_06_30_bild_gewicht_fcinr.sql
--
-- Erweitert eine BESTEHENDE Installation um die neuen Felder, ohne
-- Daten zu verlieren (kein TRUNCATE/DROP TABLE — nur ADD COLUMN).
--
-- Enthält: Rassebild (BLOB), Gewicht pro Geschlecht in breed_sizes,
-- FCI-Standardnummer (fci_nr) in breeds.
--
-- Portabel für MySQL UND MariaDB: nutzt INFORMATION_SCHEMA-Prüfungen
-- statt 'ADD COLUMN IF NOT EXISTS', da reines MySQL (z.B. 8.0/8.4)
-- diese Syntax NICHT unterstützt (nur MariaDB-Erweiterung). Dadurch
-- ist dieses Skript bei wiederholtem Ausführen sicher (idempotent).
--
-- Einfach in HeidiSQL über "SQL-Datei laden" einlesen und ausführen,
-- genau wie schema.sql oder import_hunderassen.sql.
-- =====================================================================

-- ---------------------------------------------------------------------
-- Hilfs-Prozedur: fügt eine Spalte nur hinzu, falls sie noch nicht
-- existiert. Wird unten mehrfach für die verschiedenen neuen Spalten
-- aufgerufen und am Ende wieder entfernt.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `_add_column_if_missing`;

DELIMITER $$
CREATE PROCEDURE `_add_column_if_missing`(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition TEXT
)
BEGIN
    DECLARE col_exists INT DEFAULT 0;

    SELECT COUNT(*) INTO col_exists
    FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = p_table
      AND COLUMN_NAME = p_column;

    IF col_exists = 0 THEN
        SET @ddl := CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END$$
DELIMITER ;

-- ---------------------------------------------------------------------
-- breeds: FCI-Standardnummer + Bildspeicherung
-- ---------------------------------------------------------------------
CALL `_add_column_if_missing`('breeds', 'fci_nr', 'VARCHAR(20) NULL AFTER `fci_klasse`');
CALL `_add_column_if_missing`('breeds', 'bild_blob', 'LONGBLOB NULL AFTER `beschreibung`');
CALL `_add_column_if_missing`('breeds', 'bild_mime', 'VARCHAR(50) NULL AFTER `bild_blob`');
CALL `_add_column_if_missing`('breeds', 'bild_updated_at', 'DATETIME NULL AFTER `bild_mime`');

-- ---------------------------------------------------------------------
-- breed_sizes: Gewicht pro Geschlecht (analog zur Schulterhöhe)
-- ---------------------------------------------------------------------
CALL `_add_column_if_missing`('breed_sizes', 'gewicht_ruede_min_kg', 'DECIMAL(5,1) UNSIGNED NULL AFTER `schulterhoehe_haendin_max_cm`');
CALL `_add_column_if_missing`('breed_sizes', 'gewicht_ruede_max_kg', 'DECIMAL(5,1) UNSIGNED NULL AFTER `gewicht_ruede_min_kg`');
CALL `_add_column_if_missing`('breed_sizes', 'gewicht_haendin_min_kg', 'DECIMAL(5,1) UNSIGNED NULL AFTER `gewicht_ruede_max_kg`');
CALL `_add_column_if_missing`('breed_sizes', 'gewicht_haendin_max_kg', 'DECIMAL(5,1) UNSIGNED NULL AFTER `gewicht_haendin_min_kg`');

-- ---------------------------------------------------------------------
-- Falls aus einem früheren Import bereits die (nicht mehr genutzten)
-- Spalten gewicht_min_kg/gewicht_max_kg in breed_sizes existieren
-- (ohne Geschlechtertrennung), werden deren Werte best-effort in die
-- neuen Rüde-Spalten übernommen, bevor die alten Spalten entfernt
-- werden. So geht ein bereits importierter Gewichtswert nicht verloren.
-- ---------------------------------------------------------------------
SET @old_gewicht_min_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breed_sizes' AND COLUMN_NAME = 'gewicht_min_kg'
);

SET @migrate_old_weights := IF(@old_gewicht_min_exists > 0,
    'UPDATE `breed_sizes` SET `gewicht_ruede_min_kg` = `gewicht_min_kg`, `gewicht_ruede_max_kg` = `gewicht_max_kg`
     WHERE `gewicht_ruede_min_kg` IS NULL AND `gewicht_ruede_max_kg` IS NULL',
    'SELECT 1'
);
PREPARE migrate_stmt FROM @migrate_old_weights;
EXECUTE migrate_stmt;
DEALLOCATE PREPARE migrate_stmt;

SET @drop_old_min := IF(@old_gewicht_min_exists > 0,
    'ALTER TABLE `breed_sizes` DROP COLUMN `gewicht_min_kg`',
    'SELECT 1'
);
PREPARE drop_stmt1 FROM @drop_old_min;
EXECUTE drop_stmt1;
DEALLOCATE PREPARE drop_stmt1;

SET @old_gewicht_max_exists := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breed_sizes' AND COLUMN_NAME = 'gewicht_max_kg'
);
SET @drop_old_max := IF(@old_gewicht_max_exists > 0,
    'ALTER TABLE `breed_sizes` DROP COLUMN `gewicht_max_kg`',
    'SELECT 1'
);
PREPARE drop_stmt2 FROM @drop_old_max;
EXECUTE drop_stmt2;
DEALLOCATE PREPARE drop_stmt2;

-- ---------------------------------------------------------------------
-- Aufräumen: Hilfs-Prozedur wird nicht mehr gebraucht.
-- ---------------------------------------------------------------------
DROP PROCEDURE IF EXISTS `_add_column_if_missing`;

-- ---------------------------------------------------------------------
-- Kontrolle: Spalten anzeigen, damit man in HeidiSQL sofort sieht,
-- ob alles angekommen ist.
-- ---------------------------------------------------------------------
SHOW COLUMNS FROM `breeds`;
SHOW COLUMNS FROM `breed_sizes`;
