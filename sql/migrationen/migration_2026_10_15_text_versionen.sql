-- =====================================================================
-- Migration 2026-10-15: Versionierung der Texte
--
-- 1. breeds.beschreibung_original: Stand der Beschreibung bei Einführung
--    der Versionierung (bzw. beim Anlegen einer neuen Rasse). Wird von
--    der App nie überschrieben und ist die Grundlage für die spätere
--    Gliederung der Rassentexte (siehe PLAN.md, Abschnitt 4c).
-- 2. content_revisions: jede Änderung an Rassen- und Testtexten mit
--    altem und neuem Wert, wer, wann, Anlass. Ohne FK auf breeds/tests,
--    damit die Versionen auch nach dem Löschen erhalten bleiben.
--
-- Idempotent wie migration_2026_07_10_multi_tenant.sql: jeder Schritt
-- prüft vorher über information_schema, ob er schon erledigt ist. Läuft
-- auf MariaDB 10.11 (all-inkl) und MySQL 8.x (Laragon), auch im
-- SQL-Fenster von phpMyAdmin.
--
-- Zurück: sql/migrationen/rollback_text_versionen.sql
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- 1. breeds.beschreibung_original
-- Zeichensatz/Sortierung ausdrücklich: Auf Prod ist der Tabellen-
-- Standard von breeds utf8mb4_general_ci, die Spalten sind aber
-- utf8mb4_unicode_ci. Ohne Angabe erbt die neue Spalte den Standard und
-- lässt sich dann nicht mit `beschreibung` vergleichen.
-- ---------------------------------------------------------------------
SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'beschreibung_original'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `beschreibung_original` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `beschreibung`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

-- Nur leere Felder füllen: ein zweiter Lauf überschreibt nichts.
UPDATE `breeds` SET `beschreibung_original` = `beschreibung`
WHERE `beschreibung_original` IS NULL AND `beschreibung` IS NOT NULL;

-- ---------------------------------------------------------------------
-- 2. content_revisions
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `content_revisions` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     INT UNSIGNED NULL,               -- NULL = geteilter Katalog (Rassen)
    `tabelle`       VARCHAR(40)  NOT NULL,           -- 'breeds' | 'tests'
    `datensatz_id`  INT UNSIGNED NOT NULL,
    `feld`          VARCHAR(64)  NOT NULL,
    `alter_wert`    MEDIUMTEXT   NULL,
    `neuer_wert`    MEDIUMTEXT   NULL,
    `anlass`        ENUM('bearbeitung', 'import', 'loeschung', 'wiederherstellung') NOT NULL,
    `geaendert_von` INT UNSIGNED NULL,
    `geaendert_am`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rev_datensatz` (`tabelle`, `datensatz_id`, `geaendert_am`),
    KEY `idx_rev_tenant` (`tenant_id`),
    KEY `idx_rev_geaendert_von` (`geaendert_von`),
    CONSTRAINT `fk_rev_geaendert_von` FOREIGN KEY (`geaendert_von`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
