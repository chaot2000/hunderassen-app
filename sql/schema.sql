-- =====================================================================
-- Hunderassen-Verwaltung — Datenbankschema
-- Zielplattform: MySQL 5.7+ / MariaDB 10.3+ (all-inkl Privat Shared-Hosting)
-- Charset: utf8mb4 (volle Unicode-Unterstützung, inkl. Emojis/Sonderzeichen)
--
-- WICHTIG: Diese Datei ist der EINZIGE Weg für eine Neuinstallation
-- (Prod-Erstinstallation ODER lokales Fresh-Setup). Sie enthält bereits
-- den kompletten, aktuellen Stand inkl. aller Migrationen aus
-- sql/migrationen/ (alternative_namen, TheDogAPI-Felder, dogs, halter,
-- Foto-Spalten). Die Dateien in sql/migrationen/ sind nur noch das
-- historische Archiv, wie eine bereits laufende ältere Installation
-- schrittweise auf diesen Stand gebracht wurde — für eine neue
-- Installation NICHT mehr einzeln ausführen, siehe INSTALL.md.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Tabelle: users
-- Rollenbasiertes Rechtemanagement (RBAC): 'admin' | 'user'
-- Keine öffentliche Registrierung — Anlage ausschließlich durch Admin.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`      VARCHAR(50)  NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,           -- password_hash() Output, PASSWORD_DEFAULT
    `role`          ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1, -- manuelles Sperren durch Admin möglich
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`    INT UNSIGNED NULL,                -- welcher Admin hat den Account angelegt
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_username` (`username`),
    KEY `idx_role` (`role`),
    CONSTRAINT `fk_users_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: login_attempts
-- Brute-Force-Schutz: 5 Fehlversuche -> 15 Minuten Sperre.
-- Trackt sowohl Username als auch IP, damit beide Sperrarten greifen.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `username`     VARCHAR(50)  NOT NULL,             -- Eingabe, auch wenn User nicht existiert
    `ip_address`   VARCHAR(45)  NOT NULL,              -- IPv4 oder IPv6
    `success`      TINYINT(1)   NOT NULL DEFAULT 0,
    `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_username_time` (`username`, `attempted_at`),
    KEY `idx_ip_time` (`ip_address`, `attempted_at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: tags
-- Flache Liste aller Eigenschaften/Merkmale.
-- 'category' dient nur der UI-Gruppierung (z.B. im Filter-Panel),
-- ändert nichts an der flachen, n:m-fähigen Struktur.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `tags`;
CREATE TABLE `tags` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100) NOT NULL,
    `category`    VARCHAR(50)  NULL,                  -- z.B. 'Wesen', 'Pflege', 'Eignung', 'Bewegung'
    `description` VARCHAR(255) NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tag_name` (`name`),
    KEY `idx_category` (`category`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: breeds
-- Strikte Attribute der Hunderasse.
-- Größe/Schulterhöhe NICHT mehr hier — siehe Tabelle `breed_sizes`,
-- da manche Rassen mehrere Größenklassen mit je eigener Spanne haben
-- (z.B. "klein: 30-40 cm" UND "mittel: 40-50 cm" als Varianten derselben Rasse).
-- `fci_nr` ist die offizielle FCI-Standardnummer (z.B. "166") — ein
-- eigenständiges Feld zusätzlich zur fci_klasse (1-10), da beide
-- unabhängig voneinander sind (Standardnummer ist je Rasse eindeutig,
-- die Klasse gruppiert mehrere Rassen). VARCHAR statt INT, da manche
-- Quellen führende Nullen oder Buchstaben-Suffixe verwenden.
-- Bildspeicherung als BLOB direkt in der Tabelle (kein Dateisystem-
-- Zugriff nötig, einfacher auf Shared-Hosting): `bild_blob` enthält
-- die bereits serverseitig auf max. 1200px Breite verkleinerten
-- Bilddaten, `bild_mime` den zugehörigen MIME-Type für die Auslieferung.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `breeds`;
CREATE TABLE `breeds` (
    `id`                INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`              VARCHAR(120) NOT NULL,
    `alternative_namen` TEXT NULL,               -- alternative/gebräuchliche Bezeichnungen, z. B. Abkürzungen oder andere Sprachen
    `namenszusatz`      VARCHAR(255) NULL,        -- z. B. Fellvariante oder Zuchtlinie
    `ursprungsland`   VARCHAR(100) NULL,
    `farben`          VARCHAR(255) NULL,        -- z.B. "schwarz, braun, gestromt"
    `fci_klasse`      TINYINT UNSIGNED NULL,    -- 1-10, NULL = keine FCI-Klasse
    `fci_nr`          VARCHAR(20) NULL,         -- offizielle FCI-Standardnummer, z.B. "166"
    `beschreibung`    TEXT NULL,
    `temperament`     VARCHAR(255) NULL,        -- aus TheDogAPI-Abgleich übernehmbar
    `lebenserwartung` VARCHAR(50)  NULL,        -- aus TheDogAPI-Abgleich übernehmbar
    `zuchtzweck`      VARCHAR(255) NULL,        -- aus TheDogAPI-Abgleich übernehmbar (bred_for)
    `thedogapi_id`    VARCHAR(20)  NULL,        -- verknüpfte TheDogAPI-Rasse, falls abgeglichen
    `thedogapi_stand` DATETIME     NULL,        -- Zeitpunkt des letzten Abgleichs
    `bild_blob`        LONGBLOB NULL,            -- verkleinertes Bild (max. 1200px Breite)
    `bild_mime`        VARCHAR(50) NULL,         -- z.B. 'image/jpeg', 'image/png', 'image/webp'
    `bild_updated_at`  DATETIME NULL,            -- für Cache-Busting bei der Auslieferung
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`      INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_breed_name` (`name`),
    KEY `idx_fci_klasse` (`fci_klasse`),
    KEY `idx_thedogapi_id` (`thedogapi_id`),
    CONSTRAINT `chk_fci_klasse` CHECK (`fci_klasse` IS NULL OR (`fci_klasse` BETWEEN 1 AND 10)),
    CONSTRAINT `fk_breeds_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: breed_sizes
-- Eine Rasse kann mehrere Größenklassen haben (z.B. klein UND mittel
-- als zulässige Varianten). Schulterhöhe in ganzen cm, als Spanne
-- (min-max), getrennt nach Rüde/Hündin. Gewicht analog dazu in kg,
-- ebenfalls getrennt nach Geschlecht (DECIMAL für Werte wie "2,5 kg").
-- Werte nullable, falls für ein Geschlecht keine offizielle Angabe
-- existiert.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `breed_sizes`;
CREATE TABLE `breed_sizes` (
    `id`                            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `breed_id`                      INT UNSIGNED NOT NULL,
    `groesse`                       ENUM('klein', 'mittel', 'gross', 'sehr_gross') NOT NULL,
    `schulterhoehe_ruede_min_cm`    SMALLINT UNSIGNED NULL,
    `schulterhoehe_ruede_max_cm`    SMALLINT UNSIGNED NULL,
    `schulterhoehe_haendin_min_cm`  SMALLINT UNSIGNED NULL,
    `schulterhoehe_haendin_max_cm`  SMALLINT UNSIGNED NULL,
    `gewicht_ruede_min_kg`          DECIMAL(5,1) UNSIGNED NULL,
    `gewicht_ruede_max_kg`          DECIMAL(5,1) UNSIGNED NULL,
    `gewicht_haendin_min_kg`        DECIMAL(5,1) UNSIGNED NULL,
    `gewicht_haendin_max_kg`        DECIMAL(5,1) UNSIGNED NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_breed_groesse` (`breed_id`, `groesse`),  -- jede Größenklasse nur einmal pro Rasse
    KEY `idx_groesse` (`groesse`),
    CONSTRAINT `fk_breedsizes_breed` FOREIGN KEY (`breed_id`) REFERENCES `breeds` (`id`) ON DELETE CASCADE,
    CONSTRAINT `chk_ruede_range`        CHECK (`schulterhoehe_ruede_min_cm`   IS NULL OR `schulterhoehe_ruede_max_cm`   IS NULL OR `schulterhoehe_ruede_min_cm`   <= `schulterhoehe_ruede_max_cm`),
    CONSTRAINT `chk_haendin_range`      CHECK (`schulterhoehe_haendin_min_cm` IS NULL OR `schulterhoehe_haendin_max_cm` IS NULL OR `schulterhoehe_haendin_min_cm` <= `schulterhoehe_haendin_max_cm`),
    CONSTRAINT `chk_gewicht_ruede_range`   CHECK (`gewicht_ruede_min_kg`   IS NULL OR `gewicht_ruede_max_kg`   IS NULL OR `gewicht_ruede_min_kg`   <= `gewicht_ruede_max_kg`),
    CONSTRAINT `chk_gewicht_haendin_range` CHECK (`gewicht_haendin_min_kg` IS NULL OR `gewicht_haendin_max_kg` IS NULL OR `gewicht_haendin_min_kg` <= `gewicht_haendin_max_kg`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Pivot: breed_tags (n:m zwischen breeds und tags)
-- Composite Primary Key statt Surrogate-ID — reine Verknüpfungstabelle.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `breed_tags`;
CREATE TABLE `breed_tags` (
    `breed_id` INT UNSIGNED NOT NULL,
    `tag_id`   INT UNSIGNED NOT NULL,
    PRIMARY KEY (`breed_id`, `tag_id`),
    KEY `idx_tag_id` (`tag_id`),
    CONSTRAINT `fk_breedtags_breed` FOREIGN KEY (`breed_id`) REFERENCES `breeds` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_breedtags_tag`   FOREIGN KEY (`tag_id`)   REFERENCES `tags` (`id`)   ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: activities
-- z.B. Dummy-Training, Agility, Mantrailing, Obedience
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `activities`;
CREATE TABLE `activities` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(120) NOT NULL,
    `description` TEXT NULL,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_activity_name` (`name`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Pivot: breed_activities (n:m zwischen breeds und activities)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `breed_activities`;
CREATE TABLE `breed_activities` (
    `breed_id`    INT UNSIGNED NOT NULL,
    `activity_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`breed_id`, `activity_id`),
    KEY `idx_activity_id` (`activity_id`),
    CONSTRAINT `fk_breedact_breed`    FOREIGN KEY (`breed_id`)    REFERENCES `breeds` (`id`)     ON DELETE CASCADE,
    CONSTRAINT `fk_breedact_activity` FOREIGN KEY (`activity_id`) REFERENCES `activities` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: dogs
-- Verwaltung einzelner Hunde. `breed_id` und `halter_user_id` sind
-- bewusst NULLable (Mischling bzw. noch kein Halter hinterlegt),
-- jeweils ON DELETE SET NULL, damit das Löschen einer Rasse oder
-- eines Users nicht versehentlich Hunde-Datensätze mitreißt.
-- Siehe auch migration_2026_07_02_dogs.sql für bestehende Installationen.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `dogs`;
-- ---------------------------------------------------------------------
-- Tabelle: halter
-- Eigenständige Halterverwaltung — ein Halter ist KEIN App-Benutzer
-- (siehe dogs.halter_id), bewusst getrennt, da nicht jeder Halter
-- einen Login braucht und nicht jeder Benutzer zwangsläufig Halter ist.
-- ---------------------------------------------------------------------
CREATE TABLE `halter` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(150) NOT NULL,
    `telefon`     VARCHAR(50)  NULL,
    `email`       VARCHAR(150) NULL,
    `adresse`     VARCHAR(255) NULL,
    `notizen`     TEXT NULL,
    `bild_blob`       LONGBLOB NULL,
    `bild_mime`       VARCHAR(50) NULL,
    `bild_updated_at` DATETIME NULL,
    `created_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`  DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`  INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_halter_name` (`name`),
    CONSTRAINT `fk_halter_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: zuechter
-- Eigenständig (ein Züchter ist nicht zwangsläufig auch Halter).
-- User-scoped über created_by.
-- ---------------------------------------------------------------------
CREATE TABLE `zuechter` (
    `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `zuchtname`                VARCHAR(150) NOT NULL,
    `ansprechpartner_vorname`  VARCHAR(100) NULL,
    `ansprechpartner_nachname` VARCHAR(100) NULL,
    `adresse`                  VARCHAR(255) NULL,
    `telefon`                  VARCHAR(50)  NULL,
    `email`                    VARCHAR(150) NULL,
    `webseite`                 VARCHAR(255) NULL,
    `notizen`                  TEXT NULL,
    `bild_blob`                LONGBLOB NULL,
    `bild_mime`                VARCHAR(50) NULL,
    `bild_updated_at`          DATETIME NULL,
    `created_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`               DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`               INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_zuechter_zuchtname` (`zuchtname`),
    KEY `idx_zuechter_created_by` (`created_by`),
    CONSTRAINT `fk_zuechter_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `dogs` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`            VARCHAR(120) NOT NULL,
    `geburtsdatum`    DATE NULL,
    `farbe`           VARCHAR(255) NULL,
    `breed_id`        INT UNSIGNED NULL,
    `zuechter_id`     INT UNSIGNED NULL,
    `bild_blob`       LONGBLOB NULL,
    `bild_mime`       VARCHAR(50) NULL,
    `bild_updated_at` DATETIME NULL,
    `created_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`      DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`      INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_dog_name` (`name`),
    KEY `idx_dog_breed` (`breed_id`),
    KEY `idx_dog_zuechter` (`zuechter_id`),
    KEY `idx_dog_created_by` (`created_by`),
    CONSTRAINT `fk_dogs_breed`      FOREIGN KEY (`breed_id`)    REFERENCES `breeds` (`id`)   ON DELETE SET NULL,
    CONSTRAINT `fk_dogs_zuechter`   FOREIGN KEY (`zuechter_id`) REFERENCES `zuechter` (`id`)  ON DELETE SET NULL,
    CONSTRAINT `fk_dogs_created_by` FOREIGN KEY (`created_by`)  REFERENCES `users` (`id`)     ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Pivot: dog_halter (n:m — mehrere Halter/eine Familie pro Hund,
-- ein Halter kann auch mehrere Hunde haben)
-- ---------------------------------------------------------------------
CREATE TABLE `dog_halter` (
    `dog_id`    INT UNSIGNED NOT NULL,
    `halter_id` INT UNSIGNED NOT NULL,
    PRIMARY KEY (`dog_id`, `halter_id`),
    KEY `idx_dog_halter_halter` (`halter_id`),
    CONSTRAINT `fk_doghalter_dog`    FOREIGN KEY (`dog_id`)    REFERENCES `dogs` (`id`)   ON DELETE CASCADE,
    CONSTRAINT `fk_doghalter_halter` FOREIGN KEY (`halter_id`) REFERENCES `halter` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- Initialer Admin-User
-- WICHTIG: '__PASSWORD_HASH_PLACEHOLDER__' MUSS vor dem Import ersetzt
-- werden durch einen echten Hash, erzeugt z.B. mit:
--   php -r "echo password_hash('DeinSicheresPasswort123!', PASSWORD_DEFAULT);"
-- Niemals Klartext-Passwörter in die Datenbank schreiben!
-- =====================================================================
INSERT INTO `users` (`username`, `password_hash`, `role`, `is_active`)
VALUES ('admin', '$2y$10$G7cxovylsXsqpAaWvlNDFuJj3dzWSkiJ7kkq8fiETcRjzyNAwEU.e', 'admin', 1);

-- ---------------------------------------------------------------------
-- Beispiel-Tags (optional, kann gelöscht/erweitert werden)
-- ---------------------------------------------------------------------
INSERT INTO `tags` (`name`, `category`, `description`) VALUES
('Familienfreundlich', 'Wesen', 'Gut verträglich mit Kindern und im Familienalltag'),
('Assistenzhund geeignet', 'Eignung', 'Eignet sich grundsätzlich für die Ausbildung zum Assistenzhund'),
('Sportlich', 'Bewegung', 'Hoher Bewegungsdrang, gut für aktive Halter'),
('Hohes Erziehungsniveau erforderlich', 'Wesen', 'Benötigt erfahrene Führung'),
('Geringer Pflegeaufwand', 'Pflege', 'Fell und Pflege sind unkompliziert'),
('Hoher Pflegeaufwand', 'Pflege', 'Regelmäßiges Trimmen/Bürsten notwendig'),
('Beschäftigungsbedürftig', 'Beschäftigung', 'Braucht geistige Auslastung'),
('Wenig Bewegungsdrang', 'Bewegung', 'Genügsam bei Spaziergängen'),
('Weit verbreitet', 'Verbreitung', 'Häufig anzutreffende Rasse'),
('Selten', 'Verbreitung', 'Seltene Rasse, wenig verbreitet');

-- ---------------------------------------------------------------------
-- Beispiel-Aktivitäten (optional, kann gelöscht/erweitert werden)
-- ---------------------------------------------------------------------
INSERT INTO `activities` (`name`, `description`) VALUES
('Dummy-Training', 'Apportieren und Suchspiele mit Dummies'),
('Agility', 'Hindernisparcours mit Geschicklichkeit und Tempo'),
('Mantrailing', 'Personensuche anhand der Individualgeruchsspur'),
('Obedience', 'Präzisionsgehorsam auf hohem Niveau'),
('Zugarbeit', 'Ziehen von Lasten, Schlitten oder Bollerwagen');
