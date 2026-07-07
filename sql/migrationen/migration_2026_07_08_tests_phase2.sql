-- =====================================================================
-- migration_2026_07_08_tests_phase2.sql
--
-- Phase 2 des Test-/Testdurchführungs-Frameworks: Anbindung des in
-- Phase 1 angelegten Test-Katalogs (tests/test_aufgaben/test_ergebnisse,
-- siehe migration_2026_07_07_tests_phase1.sql) an Hunde. Ein Hund kann
-- nun Testdurchführungen erfassen: welcher Test wurde durchgeführt,
-- welches Ergebnis wurde je Aufgabe beobachtet, und ein manuell
-- gesetzter Gesamtstatus.
--
-- Rein additiv: nur neue Tabellen, keine ALTERs an bestehenden
-- Tabellen (auch nicht an dogs) — CREATE TABLE IF NOT EXISTS reicht
-- als Idempotenz-Absicherung.
-- =====================================================================

SET NAMES utf8mb4;

-- ---------------------------------------------------------------------
-- Tabelle: test_durchfuehrungen
-- Die eigentliche Durchführung eines Tests bei einem Hund — ein
-- historischer Beurteilungsdatensatz. `dog_id` kaskadiert (gehört
-- untrennbar zum Hund, analog dog_halter -> dogs), `test_id` nutzt
-- bewusst RESTRICT statt SET NULL/CASCADE: SET NULL würde den
-- Datensatz kontextlos machen ("welcher Test war das?"), CASCADE
-- würde bei Löschung eines alten Tests echte Historie vernichten.
-- RESTRICT zwingt zu einer bewussten Entscheidung (siehe
-- Test::hasDurchfuehrungen()/Löschsperre in models/Test.php).
-- `status` wird MANUELL vom Nutzer gesetzt, nicht automatisch aus den
-- Einzelergebnissen berechnet (siehe test_durchfuehrung_ergebnisse).
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `test_durchfuehrungen` (
    `id`                  INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `dog_id`              INT UNSIGNED NOT NULL,
    `test_id`             INT UNSIGNED NOT NULL,
    `durchfuehrungsdatum` DATE NOT NULL,
    `status`              ENUM('offen', 'bestanden', 'nicht_bestanden') NOT NULL DEFAULT 'offen',
    `notizen`             TEXT NULL,
    `created_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`          DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`          INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_durchfuehrung_dog` (`dog_id`),
    KEY `idx_durchfuehrung_test` (`test_id`),
    KEY `idx_durchfuehrung_created_by` (`created_by`),
    CONSTRAINT `fk_durchfuehrung_dog`        FOREIGN KEY (`dog_id`)     REFERENCES `dogs` (`id`)  ON DELETE CASCADE,
    CONSTRAINT `fk_durchfuehrung_test`       FOREIGN KEY (`test_id`)    REFERENCES `tests` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_durchfuehrung_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: test_durchfuehrung_ergebnisse
-- Das tatsächlich erfasste Ergebnis je Aufgabe innerhalb einer
-- Durchführung — eine Payload-Zeile (nicht nur ein reiner Pivot, daher
-- eigene id, analog breed_sizes statt breed_tags). `aufgabe_id`/
-- `ergebnis_id` nutzen ebenfalls RESTRICT (gleiche Historien-Logik wie
-- oben). UNIQUE (durchfuehrung_id, aufgabe_id) erzwingt "genau ein
-- Ergebnis pro Aufgabe pro Durchführung" bereits auf DB-Ebene.
-- ---------------------------------------------------------------------
CREATE TABLE IF NOT EXISTS `test_durchfuehrung_ergebnisse` (
    `id`               INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `durchfuehrung_id` INT UNSIGNED NOT NULL,
    `aufgabe_id`       INT UNSIGNED NOT NULL,
    `ergebnis_id`      INT UNSIGNED NOT NULL,
    `notizen`          TEXT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_durchfuehrung_aufgabe` (`durchfuehrung_id`, `aufgabe_id`),
    KEY `idx_tde_aufgabe` (`aufgabe_id`),
    KEY `idx_tde_ergebnis` (`ergebnis_id`),
    CONSTRAINT `fk_tde_durchfuehrung` FOREIGN KEY (`durchfuehrung_id`) REFERENCES `test_durchfuehrungen` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_tde_aufgabe`       FOREIGN KEY (`aufgabe_id`)       REFERENCES `test_aufgaben` (`id`)       ON DELETE RESTRICT,
    CONSTRAINT `fk_tde_ergebnis`      FOREIGN KEY (`ergebnis_id`)      REFERENCES `test_ergebnisse` (`id`)     ON DELETE RESTRICT
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
