<?php
/**
 * tests/GroessenklasseTest.php
 *
 * Tests für die Größenklasse aus der Schulterhöhe (models/Groessenklasse.php).
 * Ohne Datenbank.
 * Aufruf: php tests/GroessenklasseTest.php, Exit-Code 0 = alles grün.
 */

declare(strict_types=1);

require __DIR__ . '/../models/Groessenklasse.php';

$fehler = 0;
function pruefe(string $beschreibung, bool $ok): void
{
    global $fehler;
    echo ($ok ? 'OK   ' : 'FAIL ') . $beschreibung . "\n";
    if (!$ok) {
        $fehler++;
    }
}

$k = fn(array ...$spannen) => Groessenklasse::ausSpannen($spannen);

pruefe('Ohne Angabe keine Klasse', $k() === [] && $k([null, null]) === [] && $k(['', '']) === []);
pruefe('20–30 cm ist klein', $k(['20', '30']) === ['klein']);
pruefe('34,9 cm ist noch klein', $k([34.9, 34.9]) === ['klein']);
pruefe('35 cm gehört zu mittel', $k([35, 35]) === ['mittel']);
pruefe('30–35 cm ist klein und mittel', $k([30, 35]) === ['klein', 'mittel']);
pruefe('40–47 cm ist mittel', $k([40, 47]) === ['mittel']);
pruefe('48 cm ist groß', $k([48, null]) === ['gross']);
pruefe('Nur Bis-Wert zählt', $k([null, 60]) === ['gross']);
pruefe('65 cm ist sehr groß', $k([65, 80]) === ['sehr_gross']);
pruefe('Labrador 54–57 cm ist groß', $k(['56', '57'], ['54', '56']) === ['gross']);
pruefe('Deutsche Dogge ab 72 cm ist sehr groß', $k(['80', null], ['72', null]) === ['sehr_gross']);
pruefe('Rüde und Hündin über eine Grenze', $k([60, 70], [55, 62]) === ['gross', 'sehr_gross']);
pruefe('Spanne über drei Klassen', $k([30, 70]) === ['gross', 'klein', 'mittel', 'sehr_gross']);
pruefe('Null und Unsinn werden ignoriert', $k([0, 'abc'], [40, 44]) === ['mittel']);
pruefe('Beschreibung mit Namen', Groessenklasse::beschreibung(['klein' => 'klein', 'mittel' => 'mittel', 'gross' => 'groß', 'sehr_gross' => 'sehr groß'])
    === 'klein unter 35 cm, mittel 35 bis unter 48 cm, groß 48 bis unter 65 cm, sehr groß ab 65 cm');

echo $fehler === 0 ? "\nAlle Prüfungen grün.\n" : "\n{$fehler} Prüfung(en) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
