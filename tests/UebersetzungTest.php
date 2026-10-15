<?php
/**
 * tests/UebersetzungTest.php
 *
 * Tests für models/Uebersetzung.php ohne Datenbank.
 * Aufruf: php tests/UebersetzungTest.php, Exit-Code 0 = alles grün.
 */

declare(strict_types=1);

require __DIR__ . '/../models/Uebersetzung.php';

$fehler = 0;
function pruefe(string $beschreibung, bool $ok): void
{
    global $fehler;
    echo ($ok ? 'OK   ' : 'FAIL ') . $beschreibung . "\n";
    if (!$ok) {
        $fehler++;
    }
}

$r = Uebersetzung::temperament('Confident, alert, playful');
pruefe('Kommaliste wird übersetzt', $r['text'] === 'selbstbewusst, aufmerksam, verspielt');
pruefe('Keine unbekannten Begriffe', $r['unbekannt'] === []);

$r = Uebersetzung::temperament('  Smart,clever , LOYAL ');
pruefe('Leerzeichen und Großschreibung egal, Doppelte entfallen', $r['text'] === 'klug, loyal');

$r = Uebersetzung::temperament('Friendly, Wobbly');
pruefe('Unbekannter Begriff: kein deutscher Text', $r['text'] === null);
pruefe('Unbekannter Begriff wird gemeldet', $r['unbekannt'] === ['wobbly']);

$r = Uebersetzung::temperament('Eager to please, Even-tempered');
pruefe('Mehrwortbegriffe', $r['text'] === 'will gefallen, ausgeglichen');

pruefe('Leere Eingabe', Uebersetzung::temperament(null) === ['text' => null, 'unbekannt' => []]);
pruefe('Nur Kommas', Uebersetzung::temperament(' , ,')['text'] === null);

foreach (Uebersetzung::woerterbuch() as $en => $de) {
    if ($en !== mb_strtolower(trim($en)) || trim($de) === '' || str_contains($en, ',')) {
        pruefe("Wörterbuch-Eintrag „{$en}“ ist sauber", false);
    }
}
pruefe('Wörterbuch hat mindestens 46 Einträge', count(Uebersetzung::woerterbuch()) >= 46);

echo $fehler === 0 ? "\nAlle Tests grün.\n" : "\n{$fehler} Test(s) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
