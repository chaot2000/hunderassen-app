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
--
-- Zwei Mandanten: 1 "Default" (aus schema.sql, mit Super-Admin "admin")
-- und 2 "CI-Mandant B" (Kürzel ci-b) mit eigenem Admin, der bewusst
-- ebenfalls "admin" heißt. Damit prüft der Rauchtest, dass der Login
-- den Mandanten unterscheidet und dass Mandant B keine Datensätze von
-- Mandant 1 sieht. Die Passwörter setzt .github/workflows/ci.yml.
-- =====================================================================

SET NAMES utf8mb4;

-- Mandant C (ci-c) hat keine Benutzer. Der Rauchtest provoziert dort
-- die Login-Sperre und prüft, dass Mandant 1 davon unberührt bleibt.
INSERT INTO `tenants` (`id`, `name`, `subdomain`, `is_active`)
VALUES
    (2, 'CI-Mandant B', 'ci-b', 1),
    (3, 'CI-Mandant C', 'ci-c', 1);

-- Platzhalter-Hash, wird in CI per UPDATE ersetzt (siehe ci.yml).
INSERT INTO `users` (`id`, `tenant_id`, `username`, `password_hash`, `role`, `is_super_admin`, `is_active`, `created_by`)
VALUES (2, 2, 'admin', '__CI_PASSWORT_WIRD_GESETZT__', 'admin', 0, 1, 1);

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

INSERT INTO `halter` (`id`, `tenant_id`, `name`, `telefon`, `email`, `created_by`)
VALUES
    (1, 1, 'CI-Test-Halter', '0123456789', 'halter@example.test', 1),
    (2, 2, 'CI-B-Halter', '0111111111', 'halter-b@example.test', 2);

INSERT INTO `zuechter` (`id`, `tenant_id`, `zuchtname`, `ansprechpartner_vorname`, `ansprechpartner_nachname`, `telefon`, `email`, `webseite`, `created_by`)
VALUES
    (1, 1, 'CI-Test-Zucht', 'Erika', 'Musterfrau', '0987654321', 'zucht@example.test', 'https://example.test', 1),
    (2, 2, 'CI-B-Zucht', 'Bernd', 'Beispiel', '0222222222', 'zucht-b@example.test', NULL, 2);

INSERT INTO `dogs` (`id`, `tenant_id`, `name`, `breed_id`, `zuechter_id`, `created_by`)
VALUES
    (1, 1, 'CI-Testhund', 1, 1, 1),
    (2, 2, 'CI-B-Hund', 1, 2, 2);

INSERT INTO `dog_halter` (`dog_id`, `halter_id`) VALUES (1, 1), (2, 2);

-- ---------------------------------------------------------------------
-- Test-Katalog (Phase 1 des Test-/Testdurchführungs-Frameworks) — ein
-- Test mit 2 Aufgaben, je 2 Ergebnissen, damit smoke-test.sh
-- admin_manage_tests.php/test_form.php mit echtem Inhalt prüfen kann.
-- ---------------------------------------------------------------------
-- Mandant B hat einen gleichnamigen Test (uq_tenant_test_name erlaubt
-- das) mit eigener Aufgabe.
INSERT INTO `tests` (`id`, `tenant_id`, `name`, `beschreibung`, `zielgruppe`, `created_by`)
VALUES
    (1, 1, 'CI-Testtest', 'Nur für automatisierte Tests angelegt.', 'beide', 1),
    (2, 2, 'CI-Testtest', 'Test von Mandant B.', 'beide', 2);

INSERT INTO `test_aufgaben` (`id`, `test_id`, `titel`, `beschreibung`)
VALUES
    (1, 1, 'CI-Aufgabe 1', 'Reaktion auf fremden Hund'),
    (2, 1, 'CI-Aufgabe 2', 'Reaktion auf lautes Geräusch'),
    (3, 2, 'CI-B-Aufgabe', 'Nur in Mandant B');

INSERT INTO `test_ergebnisse` (`id`, `aufgabe_id`, `bezeichnung`, `kategorie`)
VALUES
    (1, 1, 'Bleibt entspannt', 'bestanden'),
    (2, 1, 'Bellt kurz, beruhigt sich', 'neutral'),
    (3, 1, 'Zeigt aggressives Verhalten', 'nicht_bestanden'),
    (4, 2, 'Bleibt entspannt', 'bestanden'),
    (5, 2, 'Schreckt kurz, beruhigt sich', 'neutral'),
    (6, 3, 'B-Ergebnis', 'bestanden');

-- ---------------------------------------------------------------------
-- Testdurchführung (Phase 2) — eine erfasste Durchführung des
-- CI-Testtest bei CI-Testhund, damit smoke-test.sh dog_detail.php's
-- Historie-Sektion sowie testdurchfuehrung_detail.php mit echtem
-- Inhalt prüfen kann.
-- ---------------------------------------------------------------------
INSERT INTO `test_durchfuehrungen` (`id`, `dog_id`, `test_id`, `durchfuehrungsdatum`, `status`, `notizen`, `created_by`)
VALUES (1, 1, 1, '2026-07-01', 'bestanden', 'CI-Testnotiz zur Gesamtdurchführung.', 1);

INSERT INTO `test_durchfuehrung_ergebnisse` (`durchfuehrung_id`, `aufgabe_id`, `ergebnis_id`, `notizen`)
VALUES
    (1, 1, 1, 'CI-Testnotiz zur Aufgabe 1.'),
    (1, 2, 4, NULL);