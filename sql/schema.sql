-- =====================================================================
-- Hunderassen-Verwaltung — Datenbankschema
-- Zielplattform: MySQL 5.7+ / MariaDB 10.3+ (all-inkl Privat Shared-Hosting)
-- Charset: utf8mb4 (volle Unicode-Unterstützung, inkl. Emojis/Sonderzeichen)
--
-- WICHTIG: Diese Datei ist der EINZIGE Weg für eine Neuinstallation
-- (Prod-Erstinstallation ODER lokales Fresh-Setup). Sie enthält bereits
-- den kompletten, aktuellen Stand inkl. aller Migrationen aus
-- sql/migrationen/ (alternative_namen, TheDogAPI-Felder, dogs, halter,
-- Foto-Spalten, Multi-Tenant, Textversionen). Die Dateien in sql/migrationen/ sind nur noch das
-- historische Archiv, wie eine bereits laufende ältere Installation
-- schrittweise auf diesen Stand gebracht wurde — für eine neue
-- Installation NICHT mehr einzeln ausführen, siehe INSTALL.md.
--
-- MULTI-TENANT: Die Anwendung unterstützt Mandantenfähigkeit über
-- tenant_id Spalten in den Haupttabellen. Geteilte Katalogdaten
-- (breeds, tags, activities) bleiben global für alle Mandanten.
-- =====================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 0;

-- ---------------------------------------------------------------------
-- Tabelle: tenants
-- Verwaltet alle Mandanten der Anwendung (Multi-Tenancy)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `tenants`;
CREATE TABLE `tenants` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`        VARCHAR(100) NOT NULL,           -- Anzeigename des Mandanten
    `subdomain`   VARCHAR(50)  NOT NULL,           -- Subdomain für Identifikation
    `is_active`   TINYINT(1)   NOT NULL DEFAULT 1, -- Mandanten können deaktiviert werden
    `created_at`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_subdomain` (`subdomain`),
    KEY `idx_is_active` (`is_active`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: users
-- Rollenbasiertes Rechtemanagement (RBAC): 'admin' | 'user'
-- Keine öffentliche Registrierung — Anlage ausschließlich durch Admin.
-- Multi-Tenant: username ist pro Tenant eindeutig (tenant_id, username)
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `users`;
CREATE TABLE `users` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     INT UNSIGNED NOT NULL,           -- Mandanten-Zuordnung
    `username`      VARCHAR(50)  NOT NULL,
    `password_hash` VARCHAR(255) NOT NULL,           -- password_hash() Output, PASSWORD_DEFAULT
    `role`          ENUM('admin', 'user') NOT NULL DEFAULT 'user',
    `is_super_admin` TINYINT(1)  NOT NULL DEFAULT 0, -- mandantenübergreifend: verwaltet Mandanten, wählt tenant_id beim Anlegen von Benutzern
    `can_manage_breeds` TINYINT(1) NOT NULL DEFAULT 0, -- Portal-Recht: Rassen-Katalog verwalten (nur relevant bei role='user', Admin darf ohnehin alles)
    `can_manage_tests`  TINYINT(1) NOT NULL DEFAULT 0, -- Portal-Recht: Test-Katalog verwalten (nur relevant bei role='user', Admin darf ohnehin alles)
    `sprache`       ENUM('de', 'en') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'de', -- Sprache der Oberfläche, siehe config/i18n.php
    `is_active`     TINYINT(1)   NOT NULL DEFAULT 1, -- manuelles Sperren durch Admin möglich
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by`    INT UNSIGNED NULL,                -- welcher Admin hat den Account angelegt
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_tenant_username` (`tenant_id`, `username`),
    KEY `idx_role` (`role`),
    KEY `idx_tenant_id` (`tenant_id`),
    CONSTRAINT `fk_users_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_users_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: login_attempts
-- Brute-Force-Schutz: 5 Fehlversuche -> 15 Minuten Sperre.
-- Trackt sowohl Username als auch IP, damit beide Sperrarten greifen.
-- Gezählt wird pro Mandant (tenant_id). Kein FK: reine Protokolltabelle,
-- Einträge von vor der Mandanten-Migration haben tenant_id NULL.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `login_attempts`;
CREATE TABLE `login_attempts` (
    `id`           BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NULL,
    `username`     VARCHAR(50)  NOT NULL,             -- Eingabe, auch wenn User nicht existiert
    `ip_address`   VARCHAR(45)  NOT NULL,              -- IPv4 oder IPv6
    `success`      TINYINT(1)   NOT NULL DEFAULT 0,
    `attempted_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_username_time` (`username`, `attempted_at`),
    KEY `idx_ip_time` (`ip_address`, `attempted_at`),
    KEY `idx_tenant_time` (`tenant_id`, `attempted_at`)
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
    `name_en`           VARCHAR(120) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, -- englischer Name, z. B. aus TheDogAPI
    `alternative_namen` TEXT NULL,               -- alternative/gebräuchliche Bezeichnungen, z. B. Abkürzungen oder andere Sprachen
    `namenszusatz`      VARCHAR(255) NULL,        -- z. B. Fellvariante oder Zuchtlinie
    `ursprungsland`   VARCHAR(100) NULL,
    `farben`          VARCHAR(255) NULL,        -- z.B. "schwarz, braun, gestromt"
    `fci_klasse`      TINYINT UNSIGNED NULL,    -- 1-10, NULL = keine FCI-Klasse
    `fci_nr`          VARCHAR(20) NULL,         -- offizielle FCI-Standardnummer, z.B. "166"
    `fci_sektion`     VARCHAR(10) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,  -- z. B. "1" oder "1.1"
    `fci_sektion_name` VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, -- z. B. "Pinscher und Schnauzer"
    `arbeitspruefung` ENUM('mit', 'ohne', 'fakultativ') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,
    `beschreibung`    TEXT NULL,
    `beschreibung_original` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, -- Stand beim Anlegen bzw. bei Einführung der Versionierung, wird nie überschrieben
    `beschreibung_en` TEXT CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, -- englische Beschreibung (TheDogAPI description)
    `temperament`     VARCHAR(255) NULL,        -- deutsch, per Wörterbuch aus temperament_en oder von Hand
    `temperament_en`  VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, -- englisch, aus TheDogAPI
    `lebenserwartung` VARCHAR(50)  NULL,        -- aus TheDogAPI-Abgleich übernehmbar
    `zuchtzweck`      VARCHAR(255) NULL,        -- deutsch, von Hand übersetzt
    `zuchtzweck_en`   VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, -- englisch, aus TheDogAPI (bred_for)
    `quelle_url`      VARCHAR(255) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL,  -- Herkunft des Textes, z. B. VDH-Rasselexikon
    `quelle_stand`    DATE NULL,                -- Stand der Quelle
    `gliederung`      ENUM('keine', 'automatisch', 'geprueft') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL DEFAULT 'keine', -- siehe admin_rassentexte.php
    `thedogapi_id`    VARCHAR(20)  NULL,        -- verknüpfte TheDogAPI-Rasse, falls abgeglichen
    `thedogapi_stand` DATETIME     NULL,        -- Zeitpunkt des letzten Abgleichs
    `daten_stand`     CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, -- Prüfwert der alten Felder bei der Übernahme ins Merkmalsmodell
    `bild_blob`        LONGBLOB NULL,            -- verkleinertes Bild (max. 1200px Breite)
    `bild_mime`        VARCHAR(50) NULL,         -- z.B. 'image/jpeg', 'image/png', 'image/webp'
    `bild_updated_at`  DATETIME NULL,            -- für Cache-Busting bei der Auslieferung
    `bild_quelle`      VARCHAR(100) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, -- Herkunft, z.B. 'TheDogAPI:<Bild-ID>', NULL = eigenes Bild
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
-- Tabelle: tests
-- Test-Katalog (z.B. "Aggressionstest", "PTBS-Assistenzhund-Test") —
-- Multi-Tenant: Tests gehören zu einer Organisation, tenant_id NULL =
-- allgemeiner Test für alle Organisationen (nur Super-Admin, siehe
-- migration_2026_10_21_allgemeine_tests.sql). scope_id macht den Namen
-- je Organisation und unter den allgemeinen Tests eindeutig.
-- `zielgruppe` steuert den Filter bei der Testauswahl in testdurchfuehrung_form.php.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `tests`;
CREATE TABLE `tests` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`    INT UNSIGNED NULL,               -- Organisation, NULL = allgemein
    `name`         VARCHAR(150) NOT NULL,
    `beschreibung` TEXT NULL,
    `zielgruppe`   ENUM('welpe', 'erwachsen', 'beide') NOT NULL DEFAULT 'beide',
    `created_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`   DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`   INT UNSIGNED NULL,
    `scope_id`     INT UNSIGNED AS (IFNULL(`tenant_id`, 0)) STORED,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_scope_test_name` (`scope_id`, `name`),
    KEY `idx_test_created_by` (`created_by`),
    KEY `idx_tenant_id` (`tenant_id`),
    CONSTRAINT `fk_tests_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_tests_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: test_ausblendungen
-- Eine Organisation blendet einen allgemeinen Test für sich aus. Er
-- fehlt dann in der Auswahl für neue Durchführungen, bestehende
-- Durchführungen bleiben lesbar. Siehe models/Test.php.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `test_ausblendungen`;
CREATE TABLE `test_ausblendungen` (
    `tenant_id`  INT UNSIGNED NOT NULL,
    `test_id`    INT UNSIGNED NOT NULL,
    `created_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `created_by` INT UNSIGNED NULL,
    PRIMARY KEY (`tenant_id`, `test_id`),
    KEY `idx_ausbl_test` (`test_id`),
    KEY `idx_ausbl_created_by` (`created_by`),
    CONSTRAINT `fk_ausbl_tenant` FOREIGN KEY (`tenant_id`)  REFERENCES `tenants` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_ausbl_test`   FOREIGN KEY (`test_id`)    REFERENCES `tests` (`id`)   ON DELETE CASCADE,
    CONSTRAINT `fk_ausbl_user`   FOREIGN KEY (`created_by`) REFERENCES `users` (`id`)   ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: test_aufgaben
-- Aufgaben einer Test-Vorlage. ON DELETE CASCADE, da eine Aufgabe nicht
-- unabhängig von ihrem Test existiert (analog breed_sizes -> breeds).
-- Reihenfolge = Einfügereihenfolge (ORDER BY id), kein sort_order-Feld.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `test_aufgaben`;
CREATE TABLE `test_aufgaben` (
    `id`           INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `test_id`      INT UNSIGNED NOT NULL,
    `abschnitt`    VARCHAR(150) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL, -- Zwischenüberschrift, z. B. "Sozialisierung"
    `titel`        VARCHAR(200) NOT NULL,
    `beschreibung` TEXT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_aufgabe_test` (`test_id`),
    CONSTRAINT `fk_testaufgaben_test` FOREIGN KEY (`test_id`) REFERENCES `tests` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: test_ergebnisse
-- Mögliche Ergebnis-Optionen je Aufgabe (Definitionsdaten, nicht das
-- tatsächlich erfasste Ergebnis einer Durchführung — das kommt erst in
-- Phase 2 über test_durchfuehrung_ergebnisse). Jede Option gehört zu
-- genau einer von drei Kategorien.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `test_ergebnisse`;
CREATE TABLE `test_ergebnisse` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `aufgabe_id`  INT UNSIGNED NOT NULL,
    `bezeichnung` VARCHAR(200) NOT NULL,
    `kategorie`   ENUM('bestanden', 'neutral', 'nicht_bestanden') NOT NULL,
    PRIMARY KEY (`id`),
    KEY `idx_ergebnis_aufgabe` (`aufgabe_id`),
    CONSTRAINT `fk_testergebnisse_aufgabe` FOREIGN KEY (`aufgabe_id`) REFERENCES `test_aufgaben` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: test_durchfuehrungen
-- Phase 2 des Test-Frameworks: die eigentliche Durchführung eines
-- Tests bei einem Hund (historischer Beurteilungsdatensatz). Siehe
-- sql/migrationen/migration_2026_07_08_tests_phase2.sql für die
-- ausführliche Begründung der RESTRICT-Kaskade auf test_id (bewusste
-- Abweichung vom sonst üblichen ON DELETE SET NULL bei Verweisen auf
-- geteilte Katalogdaten) und der manuellen (nicht automatisch
-- berechneten) status-Spalte.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `test_durchfuehrungen`;
CREATE TABLE `test_durchfuehrungen` (
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
-- Durchführung (Payload-Zeile mit eigener id, kein reiner Pivot).
-- UNIQUE (durchfuehrung_id, aufgabe_id) erzwingt "genau ein Ergebnis
-- pro Aufgabe pro Durchführung" bereits auf DB-Ebene.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `test_durchfuehrung_ergebnisse`;
CREATE TABLE `test_durchfuehrung_ergebnisse` (
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

-- ---------------------------------------------------------------------
-- Tabelle: dogs
-- Verwaltung einzelner Hunde. `breed_id` und `halter_user_id` sind
-- bewusst NULLable (Mischling bzw. noch kein Halter hinterlegt),
-- jeweils ON DELETE SET NULL, damit das Löschen einer Rasse oder
-- eines Users nicht versehentlich Hunde-Datensätze mitreißt.
-- Multi-Tenant: Hunde sind tenant-spezifisch.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `dogs`;
-- ---------------------------------------------------------------------
-- Tabelle: halter
-- Eigenständige Halterverwaltung — ein Halter ist KEIN App-Benutzer
-- (siehe dogs.halter_id), bewusst getrennt, da nicht jeder Halter
-- einen Login braucht und nicht jeder Benutzer zwangsläufig Halter ist.
-- Multi-Tenant: Halter sind tenant-spezifisch.
-- ---------------------------------------------------------------------
CREATE TABLE `halter` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`   INT UNSIGNED NOT NULL,           -- Mandanten-Zuordnung
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
    KEY `idx_tenant_id` (`tenant_id`),
    CONSTRAINT `fk_halter_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_halter_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: zuechter
-- Eigenständig (ein Züchter ist nicht zwangsläufig auch Halter).
-- Multi-Tenant: Züchter sind tenant-spezifisch.
-- ---------------------------------------------------------------------
CREATE TABLE `zuechter` (
    `id`                       INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`                INT UNSIGNED NOT NULL,           -- Mandanten-Zuordnung
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
    KEY `idx_tenant_id` (`tenant_id`),
    CONSTRAINT `fk_zuechter_tenant` FOREIGN KEY (`tenant_id`) REFERENCES `tenants` (`id`) ON DELETE RESTRICT,
    CONSTRAINT `fk_zuechter_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE `dogs` (
    `id`              INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`       INT UNSIGNED NOT NULL,           -- Mandanten-Zuordnung
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
    KEY `idx_tenant_id` (`tenant_id`),
    CONSTRAINT `fk_dogs_tenant`    FOREIGN KEY (`tenant_id`)   REFERENCES `tenants` (`id`) ON DELETE RESTRICT,
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

-- ---------------------------------------------------------------------
-- Tabelle: content_revisions
-- Versionen der Rassen- und Testtexte (alter/neuer Wert je Feld). Ohne
-- FK auf breeds/tests, damit Versionen das Löschen überleben. Siehe
-- models/ContentRevision.php und migration_2026_10_15_text_versionen.sql.
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `content_revisions`;
CREATE TABLE `content_revisions` (
    `id`            BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    `tenant_id`     INT UNSIGNED NULL,               -- NULL = geteilter Katalog (Rassen)
    `tabelle`       VARCHAR(40)  NOT NULL,           -- 'breeds' | 'tests'
    `datensatz_id`  INT UNSIGNED NOT NULL,
    `feld`          VARCHAR(64)  NOT NULL,
    `alter_wert`    MEDIUMTEXT   NULL,
    `neuer_wert`    MEDIUMTEXT   NULL,
    `anlass`        ENUM('bearbeitung', 'import', 'loeschung', 'wiederherstellung', 'umstrukturierung') CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NOT NULL,
    `geaendert_von` INT UNSIGNED NULL,
    `geaendert_am`  DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rev_datensatz` (`tabelle`, `datensatz_id`, `geaendert_am`),
    KEY `idx_rev_tenant` (`tenant_id`),
    KEY `idx_rev_geaendert_von` (`geaendert_von`),
    CONSTRAINT `fk_rev_geaendert_von` FOREIGN KEY (`geaendert_von`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- Initialer Default-Tenant
-- =====================================================================
INSERT INTO `tenants` (`id`, `name`, `subdomain`, `is_active`, `created_at`)
VALUES (1, 'Default', 'default', 1, NOW());

-- =====================================================================
-- Initialer Admin-User
-- WICHTIG: '__PASSWORD_HASH_PLACEHOLDER__' MUSS vor dem Import ersetzt
-- werden durch einen echten Hash, erzeugt z.B. mit:
--   php -r "echo password_hash('DeinSicheresPasswort123!', PASSWORD_DEFAULT);"
-- Niemals Klartext-Passwörter in die Datenbank schreiben!
-- =====================================================================
INSERT INTO `users` (`tenant_id`, `username`, `password_hash`, `role`, `is_super_admin`, `is_active`)
VALUES (1, 'admin', '__PASSWORD_HASH_PLACEHOLDER__', 'admin', 1, 1);

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

-- =====================================================================
-- Merkmale und Textabschnitte (gleich migration_2026_10_19_merkmale.sql)
-- =====================================================================

CREATE TABLE IF NOT EXISTS `merkmale` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `schluessel`    VARCHAR(50)  NOT NULL,
    `typ`           ENUM('auswahl', 'mehrfach', 'stufe', 'ja_nein', 'zahl_spanne', 'land') NOT NULL,
    `gruppe`        VARCHAR(30)  NOT NULL,
    `name_de`       VARCHAR(100) NOT NULL,
    `name_en`       VARCHAR(100) NULL,
    `einheit`       VARCHAR(20)  NULL,
    `je_geschlecht` TINYINT(1)   NOT NULL DEFAULT 0,
    `filterbar`     TINYINT(1)   NOT NULL DEFAULT 1,
    `filter_verknuepfung` ENUM('und', 'oder') NOT NULL DEFAULT 'und',
    `reihenfolge`   SMALLINT     NOT NULL DEFAULT 0,
    `created_at`    DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_merkmal_schluessel` (`schluessel`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `merkmal_werte` (
    `id`          INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `merkmal_id`  INT UNSIGNED NOT NULL,
    `schluessel`  VARCHAR(60)  NOT NULL,
    `name_de`     VARCHAR(100) NOT NULL,
    `name_en`     VARCHAR(100) NULL,
    `reihenfolge` SMALLINT     NOT NULL DEFAULT 0,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_wert` (`merkmal_id`, `schluessel`),
    CONSTRAINT `fk_wert_merkmal` FOREIGN KEY (`merkmal_id`) REFERENCES `merkmale` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rasse_merkmale` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `breed_id`   INT UNSIGNED NOT NULL,
    `merkmal_id` INT UNSIGNED NOT NULL,
    `wert_id`    INT UNSIGNED NULL,
    `zahl_von`   DECIMAL(6,1) NULL,
    `zahl_bis`   DECIMAL(6,1) NULL,
    `text_wert`  VARCHAR(20)  NULL,
    `geschlecht` ENUM('alle', 'ruede', 'huendin') NOT NULL DEFAULT 'alle',
    `quelle`     ENUM('alt', 'hand', 'thedogapi', 'vdh_text') NOT NULL DEFAULT 'hand',
    `geprueft`   TINYINT(1)   NOT NULL DEFAULT 0,
    `updated_at` DATETIME     NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    KEY `idx_rm_breed` (`breed_id`),
    KEY `idx_rm_filter` (`merkmal_id`, `wert_id`),
    CONSTRAINT `fk_rm_breed` FOREIGN KEY (`breed_id`) REFERENCES `breeds` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rm_merkmal` FOREIGN KEY (`merkmal_id`) REFERENCES `merkmale` (`id`) ON DELETE CASCADE,
    CONSTRAINT `fk_rm_wert` FOREIGN KEY (`wert_id`) REFERENCES `merkmal_werte` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS `rasse_texte` (
    `id`         INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `breed_id`   INT UNSIGNED NOT NULL,
    `sprache`    ENUM('de', 'en') NOT NULL,
    `abschnitt`  ENUM('kurzportraet', 'allgemein', 'wesen', 'haltung', 'pflege', 'aussehen', 'herkunft', 'zuchtzweck', 'standard', 'weitere') NOT NULL,
    `text`       TEXT NOT NULL,
    `quelle`     ENUM('alt', 'vdh', 'thedogapi', 'hand') NOT NULL DEFAULT 'hand',
    `geprueft`   TINYINT(1) NOT NULL DEFAULT 0,
    `updated_at` DATETIME   NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_rasse_text` (`breed_id`, `sprache`, `abschnitt`),
    CONSTRAINT `fk_rt_breed` FOREIGN KEY (`breed_id`) REFERENCES `breeds` (`id`) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Merkmale und Werte (INSERT IGNORE: ein zweiter Lauf ändert nichts)
INSERT IGNORE INTO `merkmale` (`schluessel`, `typ`, `gruppe`, `name_de`, `name_en`, `einheit`, `je_geschlecht`, `reihenfolge`) VALUES
    ('schulterhoehe', 'zahl_spanne', 'koerper', 'Schulterhöhe', 'Shoulder height', 'cm', 1, 10),
    ('gewicht', 'zahl_spanne', 'koerper', 'Gewicht', 'Weight', 'kg', 1, 20),
    ('groessenklasse', 'mehrfach', 'koerper', 'Größenklasse', 'Size class', NULL, 0, 30),
    ('lebenserwartung', 'zahl_spanne', 'koerper', 'Lebenserwartung', 'Life expectancy', 'jahre', 0, 40),
    ('ursprungsland', 'land', 'herkunft', 'Ursprungsland', 'Country of origin', NULL, 0, 50),
    ('farben', 'mehrfach', 'fell', 'Farben', 'Colours', NULL, 0, 60),
    ('fellart', 'mehrfach', 'fell', 'Fellart', 'Coat type', NULL, 0, 70),
    ('pflegeaufwand', 'stufe', 'haltung', 'Pflegeaufwand', 'Grooming needs', NULL, 0, 80),
    ('bewegungsbedarf', 'stufe', 'haltung', 'Bewegungsbedarf', 'Exercise needs', NULL, 0, 90),
    ('beschaeftigungsbedarf', 'stufe', 'haltung', 'Beschäftigungsbedarf', 'Need for occupation', NULL, 0, 100),
    ('eignung', 'mehrfach', 'eignung', 'Geeignet als / für', 'Suitable as / for', NULL, 0, 110),
    ('arbeitspruefung', 'auswahl', 'arbeit', 'Arbeitsprüfung', 'Working trial', NULL, 0, 120),
    ('aktivitaeten', 'mehrfach', 'arbeit', 'Aktivitäten', 'Activities', NULL, 0, 130),
    ('wesen', 'mehrfach', 'wesen', 'Wesen', 'Character', NULL, 0, 140),
    ('verbreitung', 'auswahl', 'verbreitung', 'Verbreitung', 'Prevalence', NULL, 0, 150),
    ('weitere', 'mehrfach', 'weitere', 'Weitere Eigenschaften', 'Other traits', NULL, 0, 160);

UPDATE `merkmale` SET `filter_verknuepfung` = 'oder'
WHERE `schluessel` IN ('groessenklasse', 'farben', 'fellart', 'verbreitung', 'arbeitspruefung', 'pflegeaufwand', 'bewegungsbedarf', 'beschaeftigungsbedarf', 'ursprungsland');

INSERT IGNORE INTO `merkmal_werte` (`merkmal_id`, `schluessel`, `name_de`, `name_en`, `reihenfolge`)
SELECT m.id, v.s, v.de, v.en, v.r FROM `merkmale` m JOIN (
    SELECT 'klein' s, 'klein' de, 'small' en, 10 r
    UNION ALL SELECT 'mittel', 'mittel', 'medium', 20
    UNION ALL SELECT 'gross', 'groß', 'large', 30
    UNION ALL SELECT 'sehr_gross', 'sehr groß', 'very large', 40
) v WHERE m.schluessel = 'groessenklasse';

INSERT IGNORE INTO `merkmal_werte` (`merkmal_id`, `schluessel`, `name_de`, `name_en`, `reihenfolge`)
SELECT m.id, v.s, v.de, v.en, v.r FROM `merkmale` m JOIN (
    SELECT 'schwarz' s, 'schwarz' de, 'black' en, 10 r
    UNION ALL SELECT 'weiss', 'weiß', 'white', 20
    UNION ALL SELECT 'braun', 'braun', 'brown', 30
    UNION ALL SELECT 'leberfarben', 'leberfarben', 'liver', 40
    UNION ALL SELECT 'rot', 'rot', 'red', 50
    UNION ALL SELECT 'gelb', 'gelb', 'yellow', 60
    UNION ALL SELECT 'gold', 'gold', 'gold', 70
    UNION ALL SELECT 'falb', 'falb', 'fawn', 80
    UNION ALL SELECT 'creme', 'creme', 'cream', 90
    UNION ALL SELECT 'sand', 'sand', 'sand', 100
    UNION ALL SELECT 'grau', 'grau', 'grey', 110
    UNION ALL SELECT 'blau', 'blau', 'blue', 120
    UNION ALL SELECT 'silber', 'silber', 'silver', 130
    UNION ALL SELECT 'zobel', 'zobel', 'sable', 140
    UNION ALL SELECT 'wildfarben', 'wildfarben', 'wolf grey', 150
    UNION ALL SELECT 'schwarz_loh', 'schwarz-loh', 'black and tan', 160
    UNION ALL SELECT 'dreifarbig', 'dreifarbig', 'tricolour', 170
    UNION ALL SELECT 'gestromt', 'gestromt', 'brindle', 180
    UNION ALL SELECT 'gescheckt', 'gescheckt', 'pied', 190
    UNION ALL SELECT 'gefleckt', 'gefleckt', 'spotted', 200
    UNION ALL SELECT 'merle', 'merle', 'merle', 210
) v WHERE m.schluessel = 'farben';

INSERT IGNORE INTO `merkmal_werte` (`merkmal_id`, `schluessel`, `name_de`, `name_en`, `reihenfolge`)
SELECT m.id, v.s, v.de, v.en, v.r FROM `merkmale` m JOIN (
    SELECT 'kurzhaar' s, 'Kurzhaar' de, 'short coat' en, 10 r
    UNION ALL SELECT 'stockhaar', 'Stockhaar', 'medium coat', 20
    UNION ALL SELECT 'langhaar', 'Langhaar', 'long coat', 30
    UNION ALL SELECT 'rauhaar', 'Rauhaar', 'wire coat', 40
    UNION ALL SELECT 'gelockt', 'gelockt', 'curly coat', 50
    UNION ALL SELECT 'haarlos', 'haarlos', 'hairless', 60
) v WHERE m.schluessel = 'fellart';

INSERT IGNORE INTO `merkmal_werte` (`merkmal_id`, `schluessel`, `name_de`, `name_en`, `reihenfolge`)
SELECT m.id, v.s, v.de, v.en, v.r FROM `merkmale` m JOIN (
    SELECT 'gering' s, 'gering' de, 'low' en, 10 r
    UNION ALL SELECT 'mittel', 'mittel', 'medium', 20
    UNION ALL SELECT 'hoch', 'hoch', 'high', 30
) v WHERE m.schluessel = 'pflegeaufwand';

INSERT IGNORE INTO `merkmal_werte` (`merkmal_id`, `schluessel`, `name_de`, `name_en`, `reihenfolge`)
SELECT m.id, v.s, v.de, v.en, v.r FROM `merkmale` m JOIN (
    SELECT 'gering' s, 'gering' de, 'low' en, 10 r
    UNION ALL SELECT 'mittel', 'mittel', 'medium', 20
    UNION ALL SELECT 'hoch', 'hoch', 'high', 30
) v WHERE m.schluessel = 'bewegungsbedarf';

INSERT IGNORE INTO `merkmal_werte` (`merkmal_id`, `schluessel`, `name_de`, `name_en`, `reihenfolge`)
SELECT m.id, v.s, v.de, v.en, v.r FROM `merkmale` m JOIN (
    SELECT 'gering' s, 'gering' de, 'low' en, 10 r
    UNION ALL SELECT 'mittel', 'mittel', 'medium', 20
    UNION ALL SELECT 'hoch', 'hoch', 'high', 30
) v WHERE m.schluessel = 'beschaeftigungsbedarf';

INSERT IGNORE INTO `merkmal_werte` (`merkmal_id`, `schluessel`, `name_de`, `name_en`, `reihenfolge`)
SELECT m.id, v.s, v.de, v.en, v.r FROM `merkmale` m JOIN (
    SELECT 'familienhund' s, 'Familienhund' de, 'family dog' en, 10 r
    UNION ALL SELECT 'begleithund', 'Begleithund', 'companion dog', 20
    UNION ALL SELECT 'assistenzhund', 'Assistenzhund', 'assistance dog', 30
    UNION ALL SELECT 'anfaenger', 'Anfänger', 'first-time owners', 40
    UNION ALL SELECT 'kinder', 'Kinder', 'children', 50
    UNION ALL SELECT 'stadtwohnung', 'Stadtwohnung', 'city apartment', 60
) v WHERE m.schluessel = 'eignung';

INSERT IGNORE INTO `merkmal_werte` (`merkmal_id`, `schluessel`, `name_de`, `name_en`, `reihenfolge`)
SELECT m.id, v.s, v.de, v.en, v.r FROM `merkmale` m JOIN (
    SELECT 'mit' s, 'mit Arbeitsprüfung' de, 'with working trial' en, 10 r
    UNION ALL SELECT 'ohne', 'ohne Arbeitsprüfung', 'without working trial', 20
    UNION ALL SELECT 'fakultativ', 'Arbeitsprüfung fakultativ', 'working trial optional', 30
) v WHERE m.schluessel = 'arbeitspruefung';

INSERT IGNORE INTO `merkmal_werte` (`merkmal_id`, `schluessel`, `name_de`, `name_en`, `reihenfolge`)
SELECT m.id, v.s, v.de, v.en, v.r FROM `merkmale` m JOIN (
    SELECT 'agility' s, 'Agility' de, 'Agility' en, 10 r
    UNION ALL SELECT 'dummy_training', 'Dummy-Training', 'Dummy training', 20
    UNION ALL SELECT 'mantrailing', 'Mantrailing', 'Mantrailing', 30
    UNION ALL SELECT 'obedience', 'Obedience', 'Obedience', 40
    UNION ALL SELECT 'zugarbeit', 'Zugarbeit', 'Draught work', 50
) v WHERE m.schluessel = 'aktivitaeten';

INSERT IGNORE INTO `merkmal_werte` (`merkmal_id`, `schluessel`, `name_de`, `name_en`, `reihenfolge`)
SELECT m.id, v.s, v.de, v.en, v.r FROM `merkmale` m JOIN (
    SELECT 'selten' s, 'selten' de, 'rare' en, 10 r
    UNION ALL SELECT 'weit_verbreitet', 'weit verbreitet', 'widespread', 20
) v WHERE m.schluessel = 'verbreitung';

INSERT IGNORE INTO `merkmal_werte` (`merkmal_id`, `schluessel`, `name_de`, `name_en`, `reihenfolge`)
SELECT m.id, v.s, v.de, v.en, v.r FROM `merkmale` m JOIN (
    SELECT 'abenteuerlustig' s, 'abenteuerlustig' de, 'adventurous' en, 10 r
    UNION ALL SELECT 'aktiv', 'aktiv', 'active', 20
    UNION ALL SELECT 'anhaenglich', 'anhänglich', 'affectionate', 30
    UNION ALL SELECT 'anpassungsfaehig', 'anpassungsfähig', 'adaptable', 40
    UNION ALL SELECT 'arbeitsfreudig', 'arbeitsfreudig', 'hardworking', 50
    UNION ALL SELECT 'arbeitsorientiert', 'arbeitsorientiert', 'work-focused', 60
    UNION ALL SELECT 'aufgeschlossen', 'aufgeschlossen', 'outgoing', 70
    UNION ALL SELECT 'aufmerksam', 'aufmerksam', 'alert', 80
    UNION ALL SELECT 'aufnahmefaehig', 'aufnahmefähig', 'receptive', 90
    UNION ALL SELECT 'ausgeglichen', 'ausgeglichen', 'even tempered', 100
    UNION ALL SELECT 'beschuetzend', 'beschützend', 'protective', 110
    UNION ALL SELECT 'bestaendig', 'beständig', 'steady', 120
    UNION ALL SELECT 'charmant', 'charmant', 'charming', 130
    UNION ALL SELECT 'clownesk', 'clownesk', 'clownish', 140
    UNION ALL SELECT 'distanziert', 'distanziert', 'aloof', 150
    UNION ALL SELECT 'dominant', 'dominant', 'dominant', 160
    UNION ALL SELECT 'durchsetzungsstark', 'durchsetzungsstark', 'assertive', 170
    UNION ALL SELECT 'eifrig', 'eifrig', 'eager', 180
    UNION ALL SELECT 'eigenstaendig', 'eigenständig', 'independent', 190
    UNION ALL SELECT 'eigenwillig', 'eigenwillig', 'willful', 200
    UNION ALL SELECT 'energiegeladen', 'energiegeladen', 'energetic', 210
    UNION ALL SELECT 'entschlossen', 'entschlossen', 'determined', 220
    UNION ALL SELECT 'ergeben', 'ergeben', 'devoted', 230
    UNION ALL SELECT 'freundlich', 'freundlich', 'friendly', 240
    UNION ALL SELECT 'froehlich', 'fröhlich', 'cheerful', 250
    UNION ALL SELECT 'furchtlos', 'furchtlos', 'fearless', 260
    UNION ALL SELECT 'fuegsam', 'fügsam', 'docile', 270
    UNION ALL SELECT 'geduldig', 'geduldig', 'patient', 280
    UNION ALL SELECT 'gehorsam', 'gehorsam', 'obedient', 290
    UNION ALL SELECT 'gelassen', 'gelassen', 'composed', 300
    UNION ALL SELECT 'gesellig', 'gesellig', 'companionable', 310
    UNION ALL SELECT 'gut_erziehbar', 'gut erziehbar', 'trainable', 320
    UNION ALL SELECT 'gutmuetig', 'gutmütig', 'good-natured', 330
    UNION ALL SELECT 'hartnaeckig', 'hartnäckig', 'tenacious', 340
    UNION ALL SELECT 'intelligent', 'intelligent', 'intelligent', 350
    UNION ALL SELECT 'je_nach_abstammung_und_charakter_unterschiedlich', 'je nach Abstammung und Charakter unterschiedlich', 'variable depending on ancestry and individual traits', 360
    UNION ALL SELECT 'klug', 'klug', 'clever', 370
    UNION ALL SELECT 'kuehn', 'kühn', 'bold', 380
    UNION ALL SELECT 'lebhaft', 'lebhaft', 'lively', 390
    UNION ALL SELECT 'liebenswuerdig', 'liebenswürdig', 'amiable', 400
    UNION ALL SELECT 'liebevoll', 'liebevoll', 'loving', 410
    UNION ALL SELECT 'loyal', 'loyal', 'loyal', 420
    UNION ALL SELECT 'mutig', 'mutig', 'brave', 430
    UNION ALL SELECT 'nervenfest', 'nervenfest', 'stable', 440
    UNION ALL SELECT 'neugierig', 'neugierig', 'curious', 450
    UNION ALL SELECT 'optimistisch', 'optimistisch', 'optimistic', 460
    UNION ALL SELECT 'reaktionsschnell', 'reaktionsschnell', 'responsive', 470
    UNION ALL SELECT 'robust', 'robust', 'hardy', 480
    UNION ALL SELECT 'ruhig', 'ruhig', 'calm', 490
    UNION ALL SELECT 'sanft', 'sanft', 'gentle', 500
    UNION ALL SELECT 'sanftmuetig', 'sanftmütig', 'sweet-tempered', 510
    UNION ALL SELECT 'schelmisch', 'schelmisch', 'mischievous', 520
    UNION ALL SELECT 'selbstbewusst', 'selbstbewusst', 'confident', 530
    UNION ALL SELECT 'selbstsicher', 'selbstsicher', 'self-assured', 540
    UNION ALL SELECT 'sensibel', 'sensibel', 'sensitive', 550
    UNION ALL SELECT 'sportlich', 'sportlich', 'athletic', 560
    UNION ALL SELECT 'stur', 'stur', 'stubborn', 570
    UNION ALL SELECT 'temperamentvoll', 'temperamentvoll', 'spirited', 580
    UNION ALL SELECT 'territorial', 'territorial', 'territorial', 590
    UNION ALL SELECT 'treu', 'treu', 'faithful', 600
    UNION ALL SELECT 'unkompliziert', 'unkompliziert', 'easygoing', 610
    UNION ALL SELECT 'verlaesslich', 'verlässlich', 'trustworthy', 620
    UNION ALL SELECT 'verspielt', 'verspielt', 'fun-loving', 630
    UNION ALL SELECT 'vorsichtig', 'vorsichtig', 'cautious', 640
    UNION ALL SELECT 'wachsam', 'wachsam', 'vigilant', 650
    UNION ALL SELECT 'wendig', 'wendig', 'agile', 660
    UNION ALL SELECT 'will_gefallen', 'will gefallen', 'eager to please', 670
    UNION ALL SELECT 'wuerdevoll', 'würdevoll', 'dignified', 680
    UNION ALL SELECT 'zurueckhaltend', 'zurückhaltend', 'reserved', 690
) v WHERE m.schluessel = 'wesen';
