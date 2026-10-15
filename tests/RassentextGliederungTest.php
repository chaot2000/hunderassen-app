<?php
/**
 * tests/RassentextGliederungTest.php
 *
 * Tests für models/RassentextGliederung.php ohne Datenbank und ohne
 * PHPUnit. Aufruf: php tests/RassentextGliederungTest.php
 * Exit-Code 0 = alles grün. Läuft in .github/workflows/ci.yml.
 *
 * Die Beispieltexte sind gekürzte, selbst geschriebene Texte im Format
 * des VDH-Imports, keine Originaltexte.
 */

declare(strict_types=1);

require __DIR__ . '/../models/RassentextGliederung.php';

$fehler = 0;
function pruefe(string $beschreibung, bool $ok): void
{
    global $fehler;
    echo ($ok ? 'OK   ' : 'FAIL ') . $beschreibung . "\n";
    if (!$ok) {
        $fehler++;
    }
}

$rest = RassentextGliederung::RESTSATZ;

// ---------------------------------------------------------------
// 1. Standardformat mit Kopf, Restsatz und doppelten Ausschnitten
// ---------------------------------------------------------------
$original = "FCI-Gruppe 2: Pinscher und Schnauzer u. a. Rassen Sektion 1: \nPinscher und Schnauzer.\nOhne Arbeitsprüfung.\n\n"
    . "Ein kleiner Hund mit großem Auftritt.\n\n"
    . "Sein Wesen ist freundlich und wachsam, sein Temperament lebhaft.\n\n"
    . "Die Erziehung braucht Konsequenz, Bewegung und Beschäftigung.\n\n"
    . "Das Fell ist hart, die Ohren stehen, die Rute ist kurz.\n\n"
    . "Die Rasse stammt aus dem 17. Jahrhundert, Ursprung ist Deutschland.\n\n"
    . $rest . "\n\n## Charakter\n\nTemperament lebhaft.\n\n## Haltung\n\nErziehung braucht Konsequenz.";

$z = RassentextGliederung::zerlege($original);
pruefe('FCI-Gruppe erkannt', $z['fci_gruppe'] === 2);
pruefe('Sektion erkannt', $z['fci_sektion'] === '1');
pruefe('Sektionsname erkannt', $z['fci_sektion_name'] === 'Pinscher und Schnauzer');
pruefe('Arbeitsprüfung "ohne"', $z['arbeitspruefung'] === 'ohne');
pruefe('Kurzporträt ist der erste Absatz', $z['kurzportraet'] === 'Ein kleiner Hund mit großem Auftritt.');
pruefe('Restsatz entfernt', !str_contains((string) $z['text'], 'Hier werden Würfe'));
pruefe('Doppelte Ausschnitte entfernt', !str_contains((string) $z['text'], '## Charakter') && substr_count((string) $z['text'], 'Temperament lebhaft.') === 1);
pruefe('FCI-Kopf nicht mehr im Text', !str_contains((string) $z['text'], 'FCI-Gruppe'));
pruefe('Abschnitte in Reihenfolge', $z['abschnitte'] === ['wesen', 'haltung', 'aussehen', 'herkunft']);
pruefe('Nichts verloren', RassentextGliederung::verloreneAbsaetze($original, $z['text']) === []);

$a = RassentextGliederung::fuerAnzeige($z['text']);
pruefe('Anzeige: Kurzporträt', $a['kurzportraet'] === 'Ein kleiner Hund mit großem Auftritt.');
pruefe('Anzeige: vier Abschnitte', count($a['abschnitte']) === 4);
pruefe('Anzeige: erster Titel "Wesen"', ($a['abschnitte'][0]['titel'] ?? '') === 'Wesen');

// ---------------------------------------------------------------
// 2. Arbeitsprüfung fakultativ, Sektionsname mit Zusatz
// ---------------------------------------------------------------
$z = RassentextGliederung::zerlege("FCI-Gruppe 3: Terrier Sektion 4: Zwergterrier, ohne Arbeitsprüfung\n\nKlein und mutig.");
pruefe('Sektionsname ohne Arbeitsprüfungs-Zusatz', $z['fci_sektion_name'] === 'Zwergterrier');
$z = RassentextGliederung::zerlege("FCI-Gruppe 3: Terrier Sektion 1: \nHochläufige Terrier.\nArbeitsprüfung fakultativ.\n\nText.");
pruefe('Arbeitsprüfung "fakultativ"', $z['arbeitspruefung'] === 'fakultativ');

// ---------------------------------------------------------------
// 3. Kein Kopf, Maßzeile zuerst: kommt nicht ins Kurzporträt
// ---------------------------------------------------------------
$original = "Widerristhöhe: Rüden: 62 cm bis 68 cm\n\nEin bekannter Hütehund aus Frankreich.\n\nSein Fell ist lang und leicht gewellt.";
$z = RassentextGliederung::zerlege($original);
pruefe('Ohne Kopf: Hinweis gesetzt', in_array('FCI-Kopf nicht im Standardformat, Text unverändert übernommen.', $z['hinweise'], true));
pruefe('Maßzeile nicht im Kurzporträt', $z['kurzportraet'] === 'Ein bekannter Hütehund aus Frankreich.');
pruefe('Maßzeile unter eigenem Titel', str_contains((string) $z['text'], '## ' . RassentextGliederung::DATEN_TITEL . "\n\nWiderristhöhe"));
pruefe('Ohne Kopf: nichts verloren', RassentextGliederung::verloreneAbsaetze($original, $z['text']) === []);

// ---------------------------------------------------------------
// 4. Nur Restsatz, leerer Text, abgebrochener Satz
// ---------------------------------------------------------------
$z = RassentextGliederung::zerlege($rest);
pruefe('Nur Restsatz: kein Text', $z['text'] === null && $z['absaetze'] === 0);
$z = RassentextGliederung::zerlege('');
pruefe('Leer: kein Text', $z['text'] === null);
$z = RassentextGliederung::zerlege("Ein Satz.\n\nUnd dieser endet mitten im");
pruefe('Abgebrochener Satz: Hinweis', in_array('Der Text endet mitten im Satz (schon in der Quelle).', $z['hinweise'], true));

// ---------------------------------------------------------------
// 5. Verlustprüfung schlägt an, wenn ein Absatz fehlt
// ---------------------------------------------------------------
pruefe('Verlust wird erkannt', RassentextGliederung::verloreneAbsaetze("Erster Absatz.\n\nZweiter Absatz.", 'Erster Absatz.') === ['Zweiter Absatz.']);

// ---------------------------------------------------------------
// 6. Anzeige von Hand gegliederter Texte
// ---------------------------------------------------------------
$a = RassentextGliederung::fuerAnzeige("Kurz.\n\nNoch ein Satz.\n\n## Eigener Titel\nDirekt darunter.\n\nZweiter Absatz.");
pruefe('Hand: Einleitung', $a['einleitung'] === ['Noch ein Satz.']);
pruefe('Hand: Titel ohne Leerzeile', ($a['abschnitte'][0]['titel'] ?? '') === 'Eigener Titel' && count($a['abschnitte'][0]['absaetze']) === 2);
pruefe('Leerer Text: leere Anzeige', RassentextGliederung::fuerAnzeige(null)['kurzportraet'] === null);

echo $fehler === 0 ? "\n=== ALLE TESTS BESTANDEN ===\n" : "\n=== {$fehler} TEST(S) FEHLGESCHLAGEN ===\n";
exit($fehler === 0 ? 0 : 1);
