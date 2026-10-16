<?php
/**
 * tests/RassenFilterTest.php
 *
 * Tests für die datenbankfreien Teile von models/RassenFilter.php:
 * Eingabe lesen, Filtern mit "und"/"oder", Spannen, Zählen.
 * Aufruf: php tests/RassenFilterTest.php, Exit-Code 0 = alles grün.
 */

declare(strict_types=1);

require __DIR__ . '/../config/i18n.php';
require __DIR__ . '/../models/RassenFilter.php';

$fehler = 0;
function pruefe(string $beschreibung, bool $ok): void
{
    global $fehler;
    echo ($ok ? 'OK   ' : 'FAIL ') . $beschreibung . "\n";
    if (!$ok) {
        $fehler++;
    }
}

$werte = fn(array $schluessel) => array_fill_keys($schluessel, ['id' => 0, 'name_de' => '', 'name_en' => null, 'reihenfolge' => 0]);
$defs = [
    'groessenklasse' => ['schluessel' => 'groessenklasse', 'typ' => 'mehrfach', 'filterbar' => true, 'verknuepfung' => 'oder', 'werte' => $werte(['klein', 'mittel', 'gross'])],
    'wesen'          => ['schluessel' => 'wesen', 'typ' => 'mehrfach', 'filterbar' => true, 'verknuepfung' => 'und', 'werte' => $werte(['ruhig', 'wachsam', 'verspielt'])],
    'schulterhoehe'  => ['schluessel' => 'schulterhoehe', 'typ' => 'zahl_spanne', 'filterbar' => true, 'verknuepfung' => 'und', 'werte' => []],
    'ursprungsland'  => ['schluessel' => 'ursprungsland', 'typ' => 'land', 'filterbar' => true, 'verknuepfung' => 'und', 'werte' => []],
    'pflegeaufwand'  => ['schluessel' => 'pflegeaufwand', 'typ' => 'stufe', 'filterbar' => true, 'verknuepfung' => 'und', 'werte' => $werte(['gering', 'hoch'])],
    'geheim'         => ['schluessel' => 'geheim', 'typ' => 'mehrfach', 'filterbar' => false, 'verknuepfung' => 'und', 'werte' => $werte(['x'])],
];
$w = fn(array $schluessel) => ['werte' => array_fill_keys($schluessel, true), 'spannen' => []];
$daten = [
    1 => ['groessenklasse' => $w(['klein']), 'wesen' => $w(['ruhig', 'wachsam']), 'schulterhoehe' => ['werte' => [], 'spannen' => [[25.0, 30.0], [23.0, 28.0]]], 'ursprungsland' => $w(['DE'])],
    2 => ['groessenklasse' => $w(['mittel']), 'wesen' => $w(['ruhig']), 'schulterhoehe' => ['werte' => [], 'spannen' => [[45.0, null]]], 'ursprungsland' => $w(['GB']), 'pflegeaufwand' => $w(['hoch'])],
    3 => ['groessenklasse' => $w(['gross', 'mittel']), 'wesen' => $w(['wachsam', 'verspielt']), 'ursprungsland' => $w(['DE'])],
    4 => [],
];
$ids = [1, 2, 3, 4];

// Eingabe
$f = RassenFilter::eingabe(['m' => [
    'groessenklasse' => ['klein', 'riesig', 'klein'],
    'schulterhoehe' => ['von' => '40', 'bis' => '30,5'],
    'ursprungsland' => ['de', 'XYZ'],
    'geheim' => ['x'],
    'unbekannt' => ['a'],
    'wesen' => ['ruhig' => 'ruhig'],
]], $defs);
pruefe('Unbekannte Werte und doppelte fallen weg', ($f['groessenklasse']['werte'] ?? null) === ['klein']);
pruefe('Spanne: Komma und vertauschte Grenzen', ($f['schulterhoehe'] ?? null) === ['von' => 30.5, 'bis' => 40.0]);
pruefe('Ländercode groß, ungültiger weg', ($f['ursprungsland']['werte'] ?? null) === ['DE']);
pruefe('Nicht filterbares Merkmal wird ignoriert', !isset($f['geheim']));
pruefe('Unbekanntes Merkmal wird ignoriert', !isset($f['unbekannt']));
pruefe('Nur Listenwerte zählen (keine Schlüssel-Tricks)', !isset($f['wesen']));
pruefe('Alter Größenfilter-Link wird übernommen', (RassenFilter::eingabe(['groesse' => ['mittel']], $defs)['groessenklasse']['werte'] ?? null) === ['mittel']);
pruefe('Leere Spanne = kein Filter', !isset(RassenFilter::eingabe(['m' => ['schulterhoehe' => ['von' => '', 'bis' => 'abc']]], $defs)['schulterhoehe']));

// Anwenden
pruefe('Ohne Filter alle', RassenFilter::anwenden($ids, $daten, [], $defs) === $ids);
pruefe('Oder: klein oder gross', RassenFilter::anwenden($ids, $daten, ['groessenklasse' => ['werte' => ['klein', 'gross']]], $defs) === [1, 3]);
pruefe('Und: ruhig und wachsam', RassenFilter::anwenden($ids, $daten, ['wesen' => ['werte' => ['ruhig', 'wachsam']]], $defs) === [1]);
pruefe('Stufe ist immer oder', RassenFilter::anwenden($ids, $daten, ['pflegeaufwand' => ['werte' => ['gering', 'hoch']]], $defs) === [2]);
pruefe('Land: DE', RassenFilter::anwenden($ids, $daten, ['ursprungsland' => ['werte' => ['DE']]], $defs) === [1, 3]);
pruefe('Spanne überlappt (Hündin 23–28 trifft bis 24)', RassenFilter::anwenden($ids, $daten, ['schulterhoehe' => ['von' => null, 'bis' => 24.0]], $defs) === [1]);
pruefe('Spanne mit nur Von-Wert bei der Rasse', RassenFilter::anwenden($ids, $daten, ['schulterhoehe' => ['von' => 44.0, 'bis' => 50.0]], $defs) === [2]);
pruefe('Spanne: Rasse ohne Angabe fällt raus', !in_array(3, RassenFilter::anwenden($ids, $daten, ['schulterhoehe' => ['von' => 0.0, 'bis' => null]], $defs), true));
pruefe('Mehrere Merkmale = und', RassenFilter::anwenden($ids, $daten, ['groessenklasse' => ['werte' => ['mittel']], 'ursprungsland' => ['werte' => ['DE']]], $defs) === [3]);

// Zählen
$z = RassenFilter::zaehlen($ids, $daten, ['groessenklasse' => ['werte' => ['klein']], 'ursprungsland' => ['werte' => ['DE']]], $defs);
pruefe('Oder-Merkmal zählt ohne eigene Auswahl (mittel bei DE: 1)', ($z['groessenklasse']['mittel'] ?? 0) === 1);
pruefe('Oder-Merkmal: gewählter Wert gezählt', ($z['groessenklasse']['klein'] ?? 0) === 1);
pruefe('Andere Merkmale unter allen Filtern (wesen ruhig: 1)', ($z['wesen']['ruhig'] ?? 0) === 1 && !isset($z['wesen']['verspielt']));
pruefe('Land zählt ohne eigene Auswahl (GB: 0, weil klein)', !isset($z['ursprungsland']['GB']));
pruefe('Spannen werden nicht gezählt', !isset($z['schulterhoehe']));
pruefe('Nicht filterbare werden nicht gezählt', !isset($z['geheim']));

pruefe('hatDaten', RassenFilter::hatDaten($daten, 'pflegeaufwand') && !RassenFilter::hatDaten($daten, 'geheim'));
pruefe('Verknüpfung: Land immer oder', RassenFilter::verknuepfung($defs['ursprungsland']) === 'oder');

echo $fehler === 0 ? "\nAlle Tests grün.\n" : "\n{$fehler} Test(s) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
