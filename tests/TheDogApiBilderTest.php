<?php
/**
 * tests/TheDogApiBilderTest.php
 *
 * Tests für die Auswertung der TheDogAPI-Bildsuche (models/TheDogApiBilder.php).
 * Ohne Netz und ohne Datenbank.
 * Aufruf: php tests/TheDogApiBilderTest.php, Exit-Code 0 = alles grün.
 */

declare(strict_types=1);

require __DIR__ . '/../models/TheDogApiBilder.php';

$fehler = 0;
function pruefe(string $beschreibung, bool $ok): void
{
    global $fehler;
    echo ($ok ? 'OK   ' : 'FAIL ') . $beschreibung . "\n";
    if (!$ok) {
        $fehler++;
    }
}

$antwort = [
    ['id' => 'abc123', 'url' => 'https://cdn2.thedogapi.com/images/abc123.jpg', 'width' => 800, 'height' => 600, 'breeds' => [['id' => 7]]],
    ['id' => 'ohneRasse', 'url' => 'https://cdn4.thedogapi.com/optimized/ohneRasse.jpg', 'width' => 1000, 'height' => 900],
    ['id' => 'fremd', 'url' => 'https://cdn2.thedogapi.com/images/fremd.jpg', 'breeds' => [['id' => 8]]],
    ['id' => 'boese', 'url' => 'https://example.com/boese.jpg'],
    ['id' => 'http', 'url' => 'http://cdn2.thedogapi.com/images/http.jpg'],
    ['id' => '../x', 'url' => 'https://cdn2.thedogapi.com/images/x.jpg'],
    ['id' => 'abc123', 'url' => 'https://cdn2.thedogapi.com/images/abc123.jpg', 'breeds' => [['id' => 7]]],
    'kein Array',
];
$bilder = TheDogApiBilder::auswerten($antwort, '7');
pruefe('Zwei gültige Kandidaten', count($bilder) === 2);
pruefe('Passende Rasse bleibt', ($bilder[0]['id'] ?? '') === 'abc123' && $bilder[0]['breite'] === 800);
pruefe('Bild ohne Rassenangabe bleibt', ($bilder[1]['id'] ?? '') === 'ohneRasse');
pruefe('Bild einer anderen Rasse fällt weg', !in_array('fremd', array_column($bilder, 'id'), true));
pruefe('Fremder Host fällt weg', !in_array('boese', array_column($bilder, 'id'), true));
pruefe('Ohne HTTPS fällt weg', !in_array('http', array_column($bilder, 'id'), true));
pruefe('Ungültige ID fällt weg', !in_array('../x', array_column($bilder, 'id'), true));

pruefe('ID aus CDN-Adresse', TheDogApiBilder::idAusUrl('https://cdn2.thedogapi.com/images/BJa4kxc4X.jpg') === 'BJa4kxc4X');
pruefe('ID ohne Größenzusatz', TheDogApiBilder::idAusUrl('https://cdn2.thedogapi.com/images/S1KMGg5Vm_1280.jpg') === 'S1KMGg5Vm');
pruefe('Quelle', TheDogApiBilder::quelle('abc') === 'TheDogAPI:abc');
pruefe('Subdomain von TheDogAPI erlaubt', TheDogApiBilder::istTheDogApiUrl('https://cdn4.thedogapi.com/x.jpg'));
pruefe('Ähnlicher Host abgelehnt', !TheDogApiBilder::istTheDogApiUrl('https://thedogapi.com.example.org/x.jpg'));
pruefe('Laden von fremdem Host abgelehnt', TheDogApiBilder::laden('https://example.com/x.jpg')['success'] === false);
pruefe('Ungültige Zuordnung ohne Abruf', TheDogApiBilder::kandidaten('1; DROP')['fehler'] !== null);

echo $fehler === 0 ? "\nAlle Tests grün.\n" : "\n{$fehler} Test(s) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
