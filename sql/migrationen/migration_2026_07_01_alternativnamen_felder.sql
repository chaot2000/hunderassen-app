-- Migration: Alternative Bezeichnungen & Namenszusatz für Rassen
-- Fügt zwei neue, optionale Freitext-Spalten zur breeds-Tabelle hinzu.
-- Idempotent über information_schema-Prüfung, da MySQL (im Gegensatz zu
-- MariaDB) kein natives "ADD COLUMN IF NOT EXISTS" kennt.

DELIMITER $$

DROP PROCEDURE IF EXISTS _add_column_if_missing $$
CREATE PROCEDURE _add_column_if_missing(
    IN p_table  VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_ddl    VARCHAR(1024)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM information_schema.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @ddl = p_ddl;
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$

DELIMITER ;

CALL _add_column_if_missing(
    'breeds',
    'alternative_namen',
    "ALTER TABLE breeds ADD COLUMN alternative_namen TEXT NULL COMMENT 'Alternative/gebräuchliche Bezeichnungen der Rasse, z. B. Abkürzungen oder andere Sprachen' AFTER name"
);

CALL _add_column_if_missing(
    'breeds',
    'namenszusatz',
    "ALTER TABLE breeds ADD COLUMN namenszusatz VARCHAR(255) NULL COMMENT 'Zusatz zum Rassenamen, z. B. Fellvariante oder Zuchtlinie' AFTER alternative_namen"
);

DROP PROCEDURE IF EXISTS _add_column_if_missing;