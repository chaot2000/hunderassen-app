<?php
/**
 * tests/InhaltsDateiTest.php
 *
 * Tests für das Dateiformat des Inhalte-Repos (models/InhaltsDatei.php):
 * Schreiben und wieder Lesen ergibt denselben Stand, Fehler werden
 * erkannt. Ohne Datenbank.
 * Aufruf: php tests/InhaltsDateiTest.php, Exit-Code 0 = alles grün.
 */

declare(strict_types=1);

require __DIR__ . '/../config/i18n.php';
require __DIR__ . '/../models/InhaltsDatei.php';

$fehler = 0;
function pruefe(string $beschreibung, bool $ok): void
{
    global $fehler;
    echo ($ok ? 'OK   ' : 'FAIL ') . $beschreibung . "\n";
    if (!$ok) {
        $fehler++;
    }
}

$w = fn(array $namen) => array_map(fn($n) => ['id' => 0, 'name_de' => $n[0], 'name_en' => $n[1], 'reihenfolge' => 0], $namen);
$definitionen = [
    ['schluessel' => 'schulterhoehe', 'typ' => 'zahl_spanne', 'name_de' => 'Schulterhöhe', 'einheit' => 'cm', 'je_geschlecht' => true, 'werte' => []],
    ['schluessel' => 'lebenserwartung', 'typ' => 'zahl_spanne', 'name_de' => 'Lebenserwartung', 'einheit' => 'jahre', 'je_geschlecht' => false, 'werte' => []],
    ['schluessel' => 'ursprungsland', 'typ' => 'land', 'name_de' => 'Ursprungsland', 'einheit' => null, 'je_geschlecht' => false, 'werte' => []],
    ['schluessel' => 'farben', 'typ' => 'mehrfach', 'name_de' => 'Farben', 'einheit' => null, 'je_geschlecht' => false,
        'werte' => $w(['schwarz' => ['schwarz', 'black'], 'schwarz_loh' => ['schwarz-loh', 'black and tan']])],
    ['schluessel' => 'arbeitspruefung', 'typ' => 'auswahl', 'name_de' => 'Arbeitsprüfung', 'einheit' => null, 'je_geschlecht' => false,
        'werte' => $w(['ohne' => ['ohne Arbeitsprüfung', 'no working trial'], 'mit' => ['mit Arbeitsprüfung', 'working trial']])],
    ['schluessel' => 'hypoallergen', 'typ' => 'ja_nein', 'name_de' => 'Hypoallergen', 'einheit' => null, 'je_geschlecht' => false, 'werte' => []],
];
$werte = [
    'schulterhoehe' => ['werte' => [], 'spannen' => ['alle' => ['20', '25.5'], 'huendin' => [null, '61'], 'ruede' => ['63.5', '63.5']], 'text' => null],
    'lebenserwartung' => ['werte' => [], 'spannen' => ['alle' => ['12', '15']], 'text' => null],
    'ursprungsland' => ['werte' => [], 'spannen' => [], 'text' => 'DE'],
    'farben' => ['werte' => ['schwarz', 'schwarz_loh'], 'spannen' => [], 'text' => null],
    'arbeitspruefung' => ['werte' => ['ohne'], 'spannen' => [], 'text' => null],
    'hypoallergen' => ['werte' => [], 'spannen' => [], 'text' => 'nein'],
];
$texte = ['de' => ['kurzportraet' => "Erster Absatz.\n\nZweiter Absatz.", 'weitere' => "## Eigene Überschrift\n\nText darunter."], 'en' => ['wesen' => 'Calm.']];

$md = InhaltsDatei::rasseSchreiben(['id' => 7, 'name' => 'Testhund, Prüfrasse', 'name_en' => 'Test Dog'], $werte, $texte, $definitionen, InhaltsDatei::ABSCHNITTE);
pruefe('Spanne mit Geschlecht und Dezimalkomma', str_contains($md, '`schulterhoehe`: Rüde 63,5 cm, Hündin bis 61 cm, beide 20–25,5 cm'));
pruefe('Lebenserwartung ohne Geschlecht', str_contains($md, '`lebenserwartung`: 12–15 Jahre'));
pruefe('Land mit Name', str_contains($md, '`ursprungsland`: DE (Deutschland)'));
pruefe('Werte mit deutschem Namen', str_contains($md, '`farben`: schwarz, schwarz-loh'));
pruefe('Leerer Abschnitt fehlt', !str_contains($md, '`wesen`') || substr_count($md, '`wesen`') === 1);

$nachSchluessel = array_column($definitionen, null, 'schluessel');
$r = InhaltsDatei::rasseLesen($md, $nachSchluessel, InhaltsDatei::ABSCHNITTE);
pruefe('Lesen ohne Fehler', $r['fehler'] === []);
pruefe('id und name_en', $r['id'] === 7 && $r['name_en'] === 'Test Dog');
$gleich = true;
foreach ($werte as $s => $wert) {
    $gleich = $gleich && InhaltsDatei::wertNorm($wert) === InhaltsDatei::wertNorm($r['merkmale'][$s] ?? null);
}
pruefe('Rundreise Merkmale', $gleich);
pruefe('Rundreise Texte inkl. Überschrift im Abschnitt', $r['texte'] === ['de' => $texte['de'], 'en' => $texte['en']]);

// Fehler
$falsch = str_replace('`farben`: schwarz, schwarz-loh', '`farben`: lila', $md);
$r = InhaltsDatei::rasseLesen($falsch, $nachSchluessel, InhaltsDatei::ABSCHNITTE);
pruefe('Unbekannter Wert wird gemeldet', count($r['fehler']) === 1 && str_contains($r['fehler'][0], 'lila'));
$falsch = str_replace('`arbeitspruefung`: ohne Arbeitsprüfung', '`arbeitspruefung`: ohne Arbeitsprüfung, mit Arbeitsprüfung', $md);
pruefe('Auswahl mit zwei Werten wird gemeldet', count(InhaltsDatei::rasseLesen($falsch, $nachSchluessel, InhaltsDatei::ABSCHNITTE)['fehler']) === 1);
$falsch = str_replace('12–15 Jahre', 'Rüde 12–15 Jahre', $md);
pruefe('Geschlecht ohne je_geschlecht wird gemeldet', count(InhaltsDatei::rasseLesen($falsch, $nachSchluessel, InhaltsDatei::ABSCHNITTE)['fehler']) === 1);
$falsch = str_replace('`kurzportraet`', '`kurzportrait`', $md);
pruefe('Unbekannter Abschnitt wird gemeldet', count(InhaltsDatei::rasseLesen($falsch, $nachSchluessel, InhaltsDatei::ABSCHNITTE)['fehler']) === 1);
$leer = str_replace('`farben`: schwarz, schwarz-loh', '`farben`:', $md);
$gelesen = InhaltsDatei::rasseLesen($leer, $nachSchluessel, InhaltsDatei::ABSCHNITTE)['merkmale'];
pruefe('Leerer Wert = kein Wert', array_key_exists('farben', $gelesen) && $gelesen['farben'] === null);

// merkmale.md
$mm = InhaltsDatei::merkmaleLesen(InhaltsDatei::merkmaleSchreiben($definitionen));
pruefe('merkmale.md ohne Fehler', $mm['fehler'] === []);
pruefe('merkmale.md: Typ, Einheit, Geschlecht', $mm['definitionen']['schulterhoehe']['typ'] === 'zahl_spanne'
    && $mm['definitionen']['schulterhoehe']['einheit'] === 'cm' && $mm['definitionen']['schulterhoehe']['je_geschlecht'] === true);
pruefe('merkmale.md: Werte mit Namen', $mm['definitionen']['farben']['werte']['schwarz_loh'] === ['name_de' => 'schwarz-loh', 'name_en' => 'black and tan']);
pruefe('merkmale.md: Merkmal ohne Werte', $mm['definitionen']['ursprungsland']['typ'] === 'land' && $mm['definitionen']['ursprungsland']['werte'] === []);
$mitKomma = InhaltsDatei::merkmaleLesen("## Farben `farben`\n\nTyp: mehrfach\n\n- `x`: rot, gelb | red\n");
pruefe('merkmale.md: Name mit Komma wird gemeldet', count($mitKomma['fehler']) === 1);

// Vergleichen
pruefe('textNorm: \\r und Leerzeichen am Zeilenende', InhaltsDatei::textNorm("a  \r\nb \n") === "a\nb");
pruefe('textNorm: leer = null', InhaltsDatei::textNorm("  \n ") === null);
pruefe('wertNorm: 12.0 = 12, Reihenfolge egal', InhaltsDatei::wertNorm(['werte' => ['b', 'a'], 'spannen' => ['ruede' => ['12.0', '14']], 'text' => null])
    === InhaltsDatei::wertNorm(['werte' => ['a', 'b'], 'spannen' => ['ruede' => ['12', '14.0']]]));
pruefe('Dateiname', InhaltsDatei::dateiname(2, 'Afghanischer Windhund, Tazi') === '002-afghanischer-windhund.md'
    && InhaltsDatei::dateiname(3, 'Aïdi') === '003-aidi.md');

echo $fehler === 0 ? "\nAlle Tests grün.\n" : "\n{$fehler} Test(s) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
