<?php
/**
 * tests/MerkmalVorschlaegeTest.php
 *
 * Tests für das Herauslesen aus VDH-Texten und TheDogAPI-Werten
 * (models/MerkmalVorschlaege.php), ohne Datenbank. Die Sätze stammen
 * im Muster aus echten Rassetexten.
 * Aufruf: php tests/MerkmalVorschlaegeTest.php, Exit-Code 0 = alles grün.
 */

declare(strict_types=1);

require __DIR__ . '/../config/i18n.php';
require __DIR__ . '/../models/MerkmalVorschlaege.php';

$fehler = 0;
function pruefe(string $beschreibung, bool $ok): void
{
    global $fehler;
    echo ($ok ? 'OK   ' : 'FAIL ') . $beschreibung . "\n";
    if (!$ok) {
        $fehler++;
    }
}

function mass(string $text, string $einheit = 'cm', string $name = ''): ?array
{
    return MerkmalVorschlaege::massAusText($text, $einheit, $name)['spannen'] ?? null;
}

// Größe
pruefe('Rüden und Hündinnen in einem Satz',
    mass('Rüden erreichen eine Schulterhöhe von 58 bis 61 Zentimetern, Hündinnen 56 bis 59 Zentimeter.') === ['huendin' => [56.0, 59.0], 'ruede' => [58.0, 61.0]]);
pruefe('Hündinnen im Folgesatz ohne Stichwort',
    mass('Rüden erreichen eine Größe von 68 bis 74 Zentimetern. Hündinnen 63 bis 69 Zentimeter.') === ['huendin' => [63.0, 69.0], 'ruede' => [68.0, 74.0]]);
pruefe('Standardzeile mit Toleranz in Klammern',
    mass('Widerristhöhe: Rüden: 67 cm (+/- 3 cm), Hündinnen: 61 cm (+/- 3 cm)') === ['huendin' => [61.0, 61.0], 'ruede' => [67.0, 67.0]]);
pruefe('Ohne Geschlecht → beide',
    mass('Der 60 bis 74 Zentimeter große, glatthaarige Windhund verkörpert Eleganz.') === ['alle' => [60.0, 74.0]]);
pruefe('"bis zu" = nur Höchstwert',
    mass('Mit bis zu 80 Zentimetern Größe sind Barsois imposant.') === ['alle' => [null, 80.0]]);
pruefe('"maximal" gilt im Satz weiter',
    mass('Rüden sollten maximal 52 Zentimeter, Hündinnen 48 Zentimeter groß sein.') === ['huendin' => [null, 48.0], 'ruede' => [null, 52.0]]);
pruefe('Mindest- und Höchstmaß werden zusammengelegt',
    mass('Widerristhöhe: Mindestmass Rüden: 70 cm, Mindestmass Hündinnen: 65 cm, Höchstmass Rüden: 90 cm. Höchstmass Hündinnen:80 cm.') === ['huendin' => [65.0, 80.0], 'ruede' => [70.0, 90.0]]);
pruefe('"und größer" = nur Mindestwert',
    mass('Widerristhöhe: Rüden: 61 cm und größer, Hündinnen: 56 cm und größer.') === ['huendin' => [56.0, null], 'ruede' => [61.0, null]]);
pruefe('"bei Rüden" nach der Zahl',
    mass('Die ideale Schulterhöhe beträgt 56 bis 57 cm bei Rüden und 54 bis 56 cm bei Hündinnen.') === ['huendin' => [54.0, 56.0], 'ruede' => [56.0, 57.0]]);
pruefe('Idealwert neben der Spanne zählt nicht',
    mass('Widerristhöhe: Rüden: 68-78 cm, ideal 71-75 cm Hündinnen: 64-72 cm, ideal 66-68 cm') === ['huendin' => [64.0, 72.0], 'ruede' => [68.0, 78.0]]);
pruefe('Haarlänge und Toleranz fallen weg',
    mass('Das Haar ist etwa 7 bis 10 cm lang. Widerristhöhe: 21 bis 26 cm, Toleranz 2 cm.') === ['alle' => [21.0, 26.0]]);
pruefe('Dezimalkomma',
    mass('Rüden erreichen eine durchschnittliche Größe von 38,10 Zentimetern, Hündinnen 35,56 Zentimeter Widerristhöhe.') === ['huendin' => [35.56, 35.56], 'ruede' => [38.1, 38.1]]);
$spitz = 'Widerristhöhe: a) Wolfsspitz/Keeshond: 49 cm +/- 6 cm. b) Großspitz: 46 cm +/- 4 cm. c) Mittelspitz: 34 cm +/- 4 cm.';
pruefe('Varietät: Großspitz liest nur seine Zeile', mass($spitz, 'cm', 'Großspitz') === ['alle' => [46.0, 46.0]]);
pruefe('Varietät: Wolfsspitz über Doppelnamen', mass($spitz, 'cm', 'Wolfsspitz') === ['alle' => [49.0, 49.0]]);
pruefe('Fremde Varietät in Klammern',
    mass('Pudel gibt es in vier Größen: Großpudel (bis 60 cm), Kleinpudel (bis 45 cm), Zwergpudel (bis 35 cm).', 'cm', 'Zwergpudel') === ['alle' => [null, 35.0]]);
pruefe('Kein Stichwort, keine Größe', mass('Der Hund lebt 30 cm über dem Boden.') === null);

// Gewicht
pruefe('Gewicht mit (Rüden)/(Hündinnen)',
    mass('Das ideale Gewicht liegt bei 30 bis 32 Kilogramm (Rüden) und 25 bis 28 Kilogramm (Hündinnen).', 'kg') === ['huendin' => [25.0, 28.0], 'ruede' => [30.0, 32.0]]);
pruefe('Gewicht "für einen erwachsenen Rüden"',
    mass('Gewicht: 20 bis 23 kg für einen erwachsenen Rüden, 17 bis 20 kg für eine erwachsene Hündin.', 'kg') === ['huendin' => [17.0, 20.0], 'ruede' => [20.0, 23.0]]);
pruefe('"1 kg pro 5 cm" ist kein Gewicht', mass('Gewicht: Jeweils 1 kg pro 5 cm Widerristhöhe.', 'kg') === null);

// Farben
$f = MerkmalVorschlaege::farbenAusText('Das Haarkleid besticht in den Farben Schwarz, Schwarz-Loh, Rot und Rauchblau.');
pruefe('Farben inkl. zusammengesetzter', $f !== null && $f['werte'] === ['schwarz_loh', 'schwarz', 'rot', 'blau']);
$f = MerkmalVorschlaege::farbenAusText('Als Farben sind Zobel, Dreifarbig und Blue Merle zugelassen.');
pruefe('Zobel, dreifarbig, merle', $f !== null && $f['werte'] === ['dreifarbig', 'merle', 'zobel']);
pruefe('Augenfarbe zählt nicht', MerkmalVorschlaege::farbenAusText('Die Augen haben Farben von Bernstein bis Braun.') === null);
pruefe('"Alle Farben" ist kein Vorschlag', MerkmalVorschlaege::farbenAusText('Alle Farben sind zulässig.') === null);
$f = MerkmalVorschlaege::farbenAusText('Als Farbe ist Weiß mit lohfarbenen Abzeichen und schwarzem Nasenschwamm zugelassen.');
pruefe('Abzeichen und Nasenschwamm zählen nicht', $f !== null && $f['werte'] === ['weiss']);
pruefe('Kein Farbsatz, keine Farben', MerkmalVorschlaege::farbenAusText('Der Hund ist schwarz vor Ärger.') === null);

// TheDogAPI
pruefe('API-Spanne "23 - 29"', MerkmalVorschlaege::spanneAusApi('23 - 29', 'cm') === [23.0, 29.0]);
pruefe('API-Einzelwert', MerkmalVorschlaege::spanneAusApi('25', 'cm') === [25.0, 25.0]);
pruefe('API "NaN" → nichts', MerkmalVorschlaege::spanneAusApi('NaN', 'kg') === null);
pruefe('API unplausibel → nichts', MerkmalVorschlaege::spanneAusApi('2 - 4', 'cm') === null);
$v = MerkmalVorschlaege::ausTheDogApi(['name' => 'Affenpinscher', 'life_span' => '12 - 15 years', 'country_code' => 'DE', 'origin' => 'Germany',
    'weight' => ['metric' => '3.2-4.5'], 'height' => ['metric' => '23-29']]);
pruefe('API: Höhe beide', ($v['schulterhoehe']['wert']['spannen'] ?? null) === ['alle' => ['23', '29']]);
pruefe('API: Gewicht mit Komma-Zahl', ($v['gewicht']['wert']['spannen'] ?? null) === ['alle' => ['3.2', '4.5']]);
pruefe('API: Lebenserwartung', ($v['lebenserwartung']['wert']['spannen'] ?? null) === ['alle' => ['12', '15']]);
pruefe('API: Land', ($v['ursprungsland']['wert']['text'] ?? null) === 'DE');
pruefe('API: unbekannter Ländercode fällt weg', !isset(MerkmalVorschlaege::ausTheDogApi(['country_code' => 'XX'])['ursprungsland']));

echo $fehler === 0 ? "\nAlle Tests grün.\n" : "\n{$fehler} Test(s) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
