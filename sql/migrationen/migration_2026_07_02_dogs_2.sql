-- =====================================================================
-- migration_2026_07_02_dogs.sql
-- Neue Tabelle `dogs` für die Hundeverwaltung.
--
-- Felder lt. Anforderung:
--   - Name
--   - Geburtsdatum
--   - Farbe (Vorlage aus Rasse + freie Wahl -> ein Textfeld, das im
--     Formular per Dropdown aus breeds.farben vorbefüllt werden kann,
--     zusätzlich frei überschreibbar ist -> daher schlicht VARCHAR)
--   - Halter: `halter_user_id` als NULLABLE FK auf `users`. Das ist
--     die "künftige Verknüpfung" selbst (kein Platzhalter), aktuell
--     aber bewusst NULLable und lose (ON DELETE SET NULL), da echtes
--     Mehr-Halter-Modell (z.B. mehrere Besitzer, Zucht-Historie) noch
--     nicht entschieden ist. Für den aktuellen Stand reicht "ein
--     optionaler Halter aus der bestehenden User-Tabelle".
--   - Rasse: `breed_id` als NULLABLE FK auf `breeds` (NULL = Mischling
--     / unbekannte Rasse zulässig), ON DELETE SET NULL statt CASCADE,
--     damit ein gelöschter Rassen-Datensatz nicht versehentlich Hunde
--     mitreißt.
--
-- Idempotent via INFORMATION_SCHEMA-Prüfung (kein natives MySQL
-- "CREATE TABLE IF NOT EXISTS ... mit ALTER" nötig, da hier komplett
-- neue Tabelle -> CREATE TABLE IF NOT EXISTS reicht direkt aus).
-- =====================================================================

SET NAMES utf8mb4;

CREATE TABLE IF NOT EXISTS `dogs` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`            VARCHAR(120) NOT NULL,
    `geburtsdatum`    DATE NULL,
    `farbe`           VARCHAR(255) NULL,        -- z.B. "schwarz-loh" — Vorschlag kommt aus breeds.farben, frei überschreibbar
    `breed_id`        INT UNSIGNED NULL,
    `halter_user_id`  INT UNSIGNED NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`      INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_dog_name` (`name`),
    KEY `idx_dog_breed` (`breed_id`),
    KEY `idx_dog_halter` (`halter_user_id`),
    CONSTRAINT `fk_dogs_breed`      FOREIGN KEY (`breed_id`)       REFERENCES `breeds` (`id`) ON DELETE SET NULL,
    CONSTRAINT `fk_dogs_halter`     FOREIGN KEY (`halter_user_id`) REFERENCES `users` (`id`)  ON DELETE SET NULL,
    CONSTRAINT `fk_dogs_created_by` FOREIGN KEY (`created_by`)     REFERENCES `users` (`id`)  ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
