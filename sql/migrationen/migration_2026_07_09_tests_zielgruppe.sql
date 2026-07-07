-- =====================================================================
-- migration_2026_07_09_tests_zielgruppe.sql
--
-- Ergänzt `tests` um eine Alterskategorie (Welpe / Erwachsen / Beide),
-- damit bei der Testauswahl in testdurchfuehrung_form.php nach
-- passenden Tests für einen Welpen bzw. einen erwachsenen Hund
-- gefiltert werden kann. Reiner Auswahlwert am Test selbst — keine
-- automatische Ableitung aus dem Geburtsdatum des Hundes.
--
-- Idempotent über INFORMATION_SCHEMA-Check + bedingtes ALTER, analog
-- zu den älteren Migrationen dieses Projekts (z.B.
-- migration_2026_07_01_thedogapi_felder.sql), da hier — anders als bei
-- migration_2026_07_07_tests_phase1.sql/..._phase2.sql — eine
-- BESTEHENDE Tabelle verändert wird, nicht nur neue Tabellen angelegt
-- werden.
-- =====================================================================

SET NAMES utf8mb4;

SET @has_zielgruppe := (
    SELECT COUNT(*) FROM INFORMATION_SCHEMA.COLUMNS
    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'tests' AND COLUMN_NAME = 'zielgruppe'
);
SET @sql_zielgruppe := IF(
    @has_zielgruppe = 0,
    'ALTER TABLE `tests` ADD COLUMN `zielgruppe` ENUM(''welpe'', ''erwachsen'', ''beide'') NOT NULL DEFAULT ''beide'' AFTER `beschreibung`',
    'SELECT 1'
);
PREPARE stmt FROM @sql_zielgruppe;
EXECUTE stmt;
DEALLOCATE PREPARE stmt;
