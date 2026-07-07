-- =====================================================================
-- migration_2026_07_07_tests_phase1.sql
--
-- Phase 1 des Test-/Testdurchführungs-Frameworks: NUR der Test-Katalog
-- selbst (Tests -> Aufgaben -> Ergebnisse), als eigenständiges Modul
-- ohne jede Anbindung an Hunde. Admin-gepflegte, geteilte Katalogdaten
-- analog zu `breeds`/`tags`/`activities`.
--
-- Bewusst noch OHNE Testdurchführungen (das kommt erst in Phase 2,
-- einer eigenen Migration) — dieses Modul lässt sich damit komplett
-- unabhängig bauen, deployen und testen, ohne bestehende Tabellen
-- (dogs, ...) überhaupt anzufassen.
--
-- Rein additiv: nur neue Tabellen, keine ALTERs an bestehenden
-- Tabellen nötig, daher reicht CREATE TABLE IF NOT EXISTS als
-- Idempotenz-Absicherung (kein ALTER-Spalten-Tanz wie bei anderen
-- Migrationen in diesem Ordner nötig).
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Tabelle: tests
-- Katalog-Tabelle wie `breeds`/`halter` (mit Timestamps). `created_by`
-- dient nur der Nachvollziehbarkeit (Audit), NICHT der Sichtbarkeits-
-- Einschränkung — Tests sind geteilte Katalogdaten, sichtbar für alle
-- eingeloggten Nutzer, nur Admins dürfen sie anlegen/bearbeiten/löschen.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `tests` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`         VARCHAR(150) NOT NULL,
    `beschreibung` TEXT NULL,
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`   INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_test_name` (`name`),
    KEY `idx_test_created_by` (`created_by`),
    CONSTRAINT `fk_tests_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: test_aufgaben
-- Aufgaben einer Test-Vorlage. Existiert nicht unabhängig von ihrem
-- Test -> ON DELETE CASCADE, analog `breed_sizes` -> `breeds`.
-- Reihenfolge der Aufgaben = Einfügereihenfolge (ORDER BY id) — kein
-- eigenes sort_order-Feld, da aktuell nirgends in der App ein
-- Sortier-Feld existiert und keine Drag&Drop-Anforderung besteht.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `test_aufgaben` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `test_id`      INT UNSIGNED NOT NULL,
    `titel`        VARCHAR(200) NOT NULL,
    `beschreibung` TEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_aufgabe_test` (`test_id`),
    CONSTRAINT `fk_testaufgaben_test` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: test_ergebnisse
-- Mögliche Ergebnis-Optionen je Aufgabe (Definitionsdaten — NICHT das
-- tatsächlich in einer Durchführung erfasste Ergebnis, das kommt erst
-- in Phase 2 über `test_durchfuehrung_ergebnisse`). Jede Option ist
-- einer von drei Kategorien zugeordnet: bestanden / neutral /
-- nicht_bestanden.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `test_ergebnisse` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `aufgabe_id`  INT UNSIGNED NOT NULL,
    `bezeichnung` VARCHAR(200) NOT NULL,
    `kategorie`   ENUM('bestanden', 'neutral', 'nicht_bestanden') NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ergebnis_aufgabe` (`aufgabe_id`),
    CONSTRAINT `fk_testergebnisse_aufgabe` FOREIGN KEY (`aufgabe_id`) REFERENCES `test_aufgaben` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
