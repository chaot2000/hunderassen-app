hunderassen-- =====================================================================
-- Hunderassen-Verwaltung — Datenbankschema
-- Zielplattform: MySQL 5.7+ / MariaDB 10.3+ (all-inkl Privat Shared-Hosting)
-- Charset: utf8mb4 (volle Unicode-Unterstützung, inkl. Emojis/Sonderzeichen)
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
-- ---------------------------------------------------------------------
DROP TABLE IF EXISTS `breeds`;
CREATE TABLE `breeds` (
    `id`            INT UNSIGNED NOT NULL AUTO_INCREMENT,
    `name`          VARCHAR(120) NOT NULL,
    `ursprungsland` VARCHAR(100) NULL,
    `farben`        VARCHAR(255) NULL,        -- z.B. "schwarz, braun, gestromt"
    `fci_klasse`    TINYINT UNSIGNED NULL,    -- 1-10, NULL = keine FCI-Klasse
    `beschreibung`  TEXT NULL,
    `created_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
    `updated_at`    DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    `created_by`    INT UNSIGNED NULL,
    PRIMARY KEY (`id`),
    KEY `idx_breed_name` (`name`),
    KEY `idx_fci_klasse` (`fci_klasse`),
    CONSTRAINT `chk_fci_klasse` CHECK (`fci_klasse` IS NULL OR (`fci_klasse` BETWEEN 1 AND 10)),
    CONSTRAINT `fk_breeds_created_by` FOREIGN KEY (`created_by`) REFERENCES `users` (`id`) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ---------------------------------------------------------------------
-- Tabelle: breed_sizes
-- Eine Rasse kann mehrere Größenklassen haben (z.B. klein UND mittel
-- als zulässige Varianten). Schulterhöhe in ganzen cm, als Spanne
-- (min-max), getrennt nach Rüde/Hündin. Werte nullable, falls für
-- ein Geschlecht keine offizielle Angabe existiert.
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
    PRIMARY KEY (`id`),
    UNIQUE KEY `uq_breed_groesse` (`breed_id`, `groesse`),  -- jede Größenklasse nur einmal pro Rasse
    KEY `idx_groesse` (`groesse`),
    CONSTRAINT `fk_breedsizes_breed` FOREIGN KEY (`breed_id`) REFERENCES `breeds` (`id`) ON DELETE CASCADE,
    CONSTRAINT `chk_ruede_range`   CHECK (`schulterhoehe_ruede_min_cm`   IS NULL OR `schulterhoehe_ruede_max_cm`   IS NULL OR `schulterhoehe_ruede_min_cm`   <= `schulterhoehe_ruede_max_cm`),
    CONSTRAINT `chk_haendin_range` CHECK (`schulterhoehe_haendin_min_cm` IS NULL OR `schulterhoehe_haendin_max_cm` IS NULL OR `schulterhoehe_haendin_min_cm` <= `schulterhoehe_haendin_max_cm`)
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

SET FOREIGN_KEY_CHECKS = 1;

-- =====================================================================
-- Initialer Admin-User
-- WICHTIG: '__PASSWORD_HASH_PLACEHOLDER__' MUSS vor dem Import ersetzt
-- werden durch einen echten Hash, erzeugt z.B. mit:
--   php -r "echo password_hash('DeinSicheresPasswort123!', PASSWORD_DEFAULT);"
-- Niemals Klartext-Passwörter in die Datenbank schreiben!
-- test123
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
