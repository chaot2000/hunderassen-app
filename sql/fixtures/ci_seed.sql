-- =====================================================================
-- sql/fixtures/ci_seed.sql
--
-- Minimale Testdaten NUR für automatisierte Tests (GitHub Actions CI
-- und optional lokal). NICHT auf Prod oder in der normalen Dev-DB
-- ausführen — ist ausschließlich für eine frisch mit schema.sql
-- angelegte, leere Test-Datenbank gedacht.
--
-- Liefert genug Inhalt, damit die Rauchtests (bin/smoke-test.sh)
-- echte Seiten mit echtem Inhalt aufrufen können (breed_detail.php,
-- dog_detail.php, halter_detail.php, zuechter_detail.php), statt nur
-- leere Listen zu sehen.
-- =====================================================================

SET NAMES utf8mb4;

INSERT INTO `breeds` (`id`, `name`, `ursprungsland`, `farben`, `fci_klasse`, `beschreibung`, `created_by`)
VALUES (1, 'CI-Testrasse', 'Deutschland', 'schwarz, braun', 1, 'Nur für automatisierte Tests angelegt.', 1);

INSERT INTO `breed_sizes` (`breed_id`, `groesse`, `schulterhoehe_ruede_min_cm`, `schulterhoehe_ruede_max_cm`)
VALUES (1, 'mittel', 40, 50);

-- WICHTIG: `tags` und `activities` werden bereits in schema.sql mit
-- einer Basis-Taxonomie befüllt (siehe dortige INSERT-Statements) —
-- hier daher bewusst OHNE festen id-Wert einfügen (sonst Primary-Key-
-- Konflikt) und per eindeutigem Namen nachschlagen.
INSERT INTO `tags` (`name`, `category`) VALUES ('CI-Testtag', 'Wesen');
INSERT INTO `breed_tags` (`breed_id`, `tag_id`)
    SELECT 1, id FROM `tags` WHERE name = 'CI-Testtag' LIMIT 1;

INSERT INTO `halter` (`id`, `name`, `telefon`, `email`, `created_by`)
VALUES (1, 'CI-Test-Halter', '0123456789', 'halter@example.test', 1);

INSERT INTO `zuechter` (`id`, `zuchtname`, `ansprechpartner_vorname`, `ansprechpartner_nachname`, `telefon`, `email`, `webseite`, `created_by`)
VALUES (1, 'CI-Test-Zucht', 'Erika', 'Musterfrau', '0987654321', 'zucht@example.test', 'https://example.test', 1);

INSERT INTO `dogs` (`id`, `name`, `breed_id`, `zuechter_id`, `created_by`)
VALUES (1, 'CI-Testhund', 1, 1, 1);

INSERT INTO `dog_halter` (`dog_id`, `halter_id`) VALUES (1, 1);