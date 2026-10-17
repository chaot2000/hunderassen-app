<?php
/**
 * tests/TestImportTest.php
 *
 * Tests für das Einlesen der Testdatei aus tools/excel_tests_umwandeln.py
 * (models/TestImport.php, nur lesen()). Ohne Datenbank.
 * Aufruf: php tests/TestImportTest.php, Exit-Code 0 = alles grün.
 */

declare(strict_types=1);

require __DIR__ . '/../config/i18n.php';
require __DIR__ . '/../config/security.php';
require __DIR__ . '/../models/TestImport.php';

$fehler = 0;
function pruefe(string $beschreibung, bool $ok): void
{
    global $fehler;
    echo ($ok ? 'OK   ' : 'FAIL ') . $beschreibung . "\n";
    if (!$ok) {
        $fehler++;
    }
}

$beispiel = (string) file_get_contents(__DIR__ . '/fixtures/tests_import_beispiel.json');
$r = TestImport::lesen($beispiel);
pruefe('Beispieldatei wird gelesen', $r['daten'] !== null && $r['fehler'] === null);
pruefe('Zwei Tests', count($r['daten']['tests'] ?? []) === 2);
pruefe('Warnungen werden durchgereicht', ($r['daten']['warnungen'] ?? []) === ['ci_beispiel.xlsx: erfundene Warnung für die Anzeige']);
pruefe('Abschnitt bleibt erhalten', ($r['daten']['tests'][0]['aufgaben'][0]['abschnitt'] ?? null) === 'CI-Abschnitt Wesen');

$daten = json_decode($beispiel, true);

$kaputt = $daten;
$kaputt['format'] = 'etwas-anderes';
pruefe('Falsches Format wird abgelehnt', TestImport::lesen(json_encode($kaputt))['daten'] === null);

$kaputt = $daten;
$kaputt['version'] = 2;
pruefe('Unbekannte Version wird abgelehnt', TestImport::lesen(json_encode($kaputt))['daten'] === null);

$kaputt = $daten;
$kaputt['tests'] = [];
pruefe('Leere Testliste wird abgelehnt', TestImport::lesen(json_encode($kaputt))['daten'] === null);

$kaputt = $daten;
unset($kaputt['tests'][1]['aufgaben']);
pruefe('Test ohne Aufgaben wird abgelehnt', TestImport::lesen(json_encode($kaputt))['daten'] === null);

$kaputt = $daten;
unset($kaputt['tests'][0]['aufgaben'][1]['ergebnisse']);
pruefe('Aufgabe ohne Ergebnisliste wird abgelehnt', TestImport::lesen(json_encode($kaputt))['daten'] === null);

pruefe('Kein JSON wird abgelehnt', TestImport::lesen('kein json')['daten'] === null);

$kaputt = $daten;
$kaputt['warnungen'] = ['ok', 42, ['x']];
pruefe('Nur Text-Warnungen bleiben', TestImport::lesen(json_encode($kaputt))['daten']['warnungen'] === ['ok']);

echo $fehler === 0 ? "\nAlle Tests grün.\n" : "\n{$fehler} Test(s) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
