-- =====================================================================
-- Migration 2026-10-19: Merkmale und Textabschnitte (PLAN.md Abschnitt 10)
--
-- Neue Tabellen merkmale, merkmal_werte, rasse_merkmale, rasse_texte und
-- breeds.daten_stand. Merkmale und Werte haben deutsche und
-- englische Namen (Mapping statt Doppeleingabe je Rasse).
--
-- Die vorhandenen Daten (breed_sizes, Tags, Aktivitäten, Arbeitsprüfung,
-- Farben, Land, Texte) überträgt models/RassenDaten.php beim nächsten
-- Aufruf einer Rasse (Quelle 'alt'), sobald sich der Prüfwert über die
-- alten Felder von breeds.daten_stand unterscheidet. Alte Spalten bleiben
-- bis zur Aufräum-Migration.
--
-- Idempotent, MariaDB 10.11 und MySQL 8.x.
-- Zurück: sql/migrationen/rollback_merkmale.sql
-- =====================================================================

SET NAMES utf8mb4;

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

SET @sql := IF(EXISTS(SELECT 1 FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'breeds' AND COLUMN_NAME = 'daten_stand'), 'DO 0', 'ALTER TABLE `breeds` ADD COLUMN `daten_stand` CHAR(32) CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci NULL AFTER `thedogapi_stand`');
PREPARE stmt FROM @sql; EXECUTE stmt; DEALLOCATE PREPARE stmt;

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
