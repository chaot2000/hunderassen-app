-- ---------------------------------------------------------------------
-- migration_2026_07_01_thedogapi_felder.sql
--
-- Fügt Felder für die Anreicherung aus TheDogAPI hinzu:
--   temperament     – z.B. "Verspielt, wachsam, freundlich"
--   lebenserwartung – z.B. "10 - 12 Jahre" (bewusst als String, da
--                     TheDogAPI Spannen liefert, keine Einzelzahl)
--   zuchtzweck      – ursprünglicher Verwendungszweck der Rasse
--   thedogapi_id    – ID des gematchten TheDogAPI-Eintrags, dient als
--                     Idempotenz-Marker: einmal gematchte/übernommene
--                     Rassen werden bei erneutem Lauf nicht nochmal
--                     zur Review vorgeschlagen (außer man will das
--                     explizit erzwingen)
--   thedogapi_stand – Datum der letzten Übernahme, rein informativ
--
-- Sicher mehrfach ausführbar: prüft vor jedem ADD COLUMN über
-- INFORMATION_SCHEMA, ob die Spalte schon existiert (siehe bereits
-- etabliertes Muster aus migration_2026_06_30_bild_gewicht_fcinr.sql,
-- notwendig weil "ADD COLUMN IF NOT EXISTS" nur MariaDB kann, nicht
-- MySQL 8.x).
-- ---------------------------------------------------------------------

DELIMITER $$

DROP PROCEDURE IF EXISTS _add_column_if_missing $$
CREATE PROCEDURE _add_column_if_missing(
    IN p_table VARCHAR(64),
    IN p_column VARCHAR(64),
    IN p_definition VARCHAR(255)
)
BEGIN
    IF NOT EXISTS (
        SELECT 1 FROM INFORMATION_SCHEMA.COLUMNS
        WHERE TABLE_SCHEMA = DATABASE()
          AND TABLE_NAME = p_table
          AND COLUMN_NAME = p_column
    ) THEN
        SET @ddl = CONCAT('ALTER TABLE `', p_table, '` ADD COLUMN `', p_column, '` ', p_definition);
        PREPARE stmt FROM @ddl;
        EXECUTE stmt;
        DEALLOCATE PREPARE stmt;
    END IF;
END $$

DELIMITER ;

CALL _add_column_if_missing('breeds', 'temperament',     'VARCHAR(255) NULL AFTER beschreibung');
CALL _add_column_if_missing('breeds', 'lebenserwartung', 'VARCHAR(50)  NULL AFTER temperament');
CALL _add_column_if_missing('breeds', 'zuchtzweck',      'VARCHAR(255) NULL AFTER lebenserwartung');
CALL _add_column_if_missing('breeds', 'thedogapi_id',    'VARCHAR(20)  NULL AFTER zuchtzweck');
CALL _add_column_if_missing('breeds', 'thedogapi_stand', 'DATETIME     NULL AFTER thedogapi_id');

DROP PROCEDURE IF EXISTS _add_column_if_missing;

-- Index für schnellen Re-Check "schon abgeglichen?" in Stufe 1.
-- Auch hier über INFORMATION_SCHEMA statt "ADD INDEX IF NOT EXISTS",
-- da dessen Unterstützung je nach MySQL/MariaDB-Version schwankt —
-- gleiches Vorsichtsprinzip wie bei den Spalten oben.
SET @idx_exists = (
    SELECT COUNT(1) FROM INFORMATION_SCHEMA.STATISTICS
    WHERE TABLE_SCHEMA = DATABASE()
      AND TABLE_NAME = 'breeds'
      AND INDEX_NAME = 'idx_thedogapi_id'
);

SET @ddl = IF(@idx_exists = 0,
    'ALTER TABLE `breeds` ADD INDEX `idx_thedogapi_id` (`thedogapi_id`)',
    'SELECT 1'
);
PREPARE stmt FROM @ddl;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
