<?php
/**
 * tests/TheDogApiAbgleichTest.php
 *
 * Tests für models/TheDogApiAbgleich.php ohne Datenbank und ohne API.
 * Aufruf: php tests/TheDogApiAbgleichTest.php, Exit-Code 0 = alles grün.
 */

declare(strict_types=1);

require __DIR__ . '/../models/TheDogApiAbgleich.php';

$fehler = 0;
function pruefe(string $beschreibung, bool $ok): void
{
    global $fehler;
    echo ($ok ? 'OK   ' : 'FAIL ') . $beschreibung . "\n";
    if (!$ok) {
        $fehler++;
    }
}

$api = [];
foreach (['Canaan Dog', 'Miniature Schnauzer', 'Giant Schnauzer', 'French Bulldog', 'Bullmastiff', 'Caucasian Shepherd Dog',
          'Chinese Crested', 'Great Dane', 'Tibetan Mastiff', 'Smooth Fox Terrier', 'Toy Fox Terrier', 'Ariège Pointer',
          'Afghan Hound', 'Spanish Greyhound', 'Small Münsterländer', 'Large Münsterländer'] as $i => $name) {
    $api[] = ['id' => (string) ($i + 1), 'name' => $name];
}
$zu = function (array $rasse) use ($api): ?array {
    return TheDogApiAbgleich::bestesMatch($rasse + ['name_en' => null, 'alternative_namen' => null, 'thedogapi_id' => null], $api);
};
$name = fn(?array $m) => $m['breed']['name'] ?? null;

// Aufbereitung und Übersetzung
pruefe('Akzente und Satzzeichen weg', TheDogApiAbgleich::aufbereiten("Braque de l'Ariège") === 'braque de l ariege');
pruefe('Umlaute', TheDogApiAbgleich::aufbereiten('Französische Bulldogge') === 'franzosische bulldogge');
pruefe('Ganze Wörter: deutscher → german, nicht germaner', TheDogApiAbgleich::uebersetzen('deutscher pinscher') === 'german pinscher');
pruefe('Zusammengesetzt: zwergschnauzer', TheDogApiAbgleich::uebersetzen('zwergschnauzer') === 'miniature schnauzer');
pruefe('Unbekanntes Wort bleibt', TheDogApiAbgleich::uebersetzen('xyzhund') === 'xyzhund');
pruefe('Teilwort ohne bekannten Rest bleibt', TheDogApiAbgleich::uebersetzen('zwergelefant') === 'zwergelefant');

// Kandidaten
$k = TheDogApiAbgleich::kandidaten('Canaan Dog, Kanaan-Hund');
pruefe('Originalname vorn ist Kandidat', in_array('canaan dog', $k, true));
pruefe('Deutscher Name ist Kandidat', in_array('kanaan hund', $k, true));
$k = TheDogApiAbgleich::kandidaten('Irish Glen Of Imaal Terrier, Irischer Glen of Imaa…');
pruefe('Gekürzter Teil fällt weg', !in_array('irischer glen of imaa', $k, true));
pruefe('Klammerzusatz zusätzlich ohne', in_array('weimaraner', TheDogApiAbgleich::kandidaten('Weimaraner (Kurzhaar)'), true));

// Zuordnung
$m = $zu(['name' => 'Canaan Dog, Kanaan-Hund']);
pruefe('Originalname exakt → 98', $name($m) === 'Canaan Dog' && $m['score'] === 98);
$m = $zu(['name' => 'Zwergschnauzer']);
pruefe('Zwergschnauzer → Miniature Schnauzer (übersetzt)', $name($m) === 'Miniature Schnauzer' && $m['score'] >= TheDogApiAbgleich::SICHER);
$m = $zu(['name' => 'Französische Bulldogge']);
pruefe('Französische Bulldogge → French Bulldog, nicht Bullmastiff', $name($m) === 'French Bulldog');
$m = $zu(['name' => 'Kaukasischer Schäferhund']);
pruefe('Kaukasischer Schäferhund → Caucasian Shepherd Dog', $name($m) === 'Caucasian Shepherd Dog');
$m = $zu(['name' => 'Chinese Crested Dog (Powderpuff)']);
pruefe('Gleiche Wörter ohne "dog" → Chinese Crested', $name($m) === 'Chinese Crested' && $m['score'] === 92);
$m = $zu(['name' => 'Deutsche Dogge']);
pruefe('Feste Zuordnung: Deutsche Dogge → Great Dane', $name($m) === 'Great Dane' && $m['score'] === 100);
$m = $zu(['name' => 'Fox Terrier (Glatthaar)']);
pruefe('Fox Terrier (Glatthaar) → Smooth Fox Terrier', $name($m) === 'Smooth Fox Terrier');
$m = $zu(['name' => 'Afghanischer Windhund']);
pruefe('Afghanischer Windhund → Afghan Hound, nicht Spanish Greyhound', $name($m) === 'Afghan Hound');
$m = $zu(['name' => 'Galgo Español']);
pruefe('Galgo Español → Spanish Greyhound, nicht Alano Español', $name($m) === 'Spanish Greyhound' && $m['score'] >= TheDogApiAbgleich::SICHER);
$m = $zu(['name' => 'Kleiner Münsterländer']);
pruefe('Kleiner Münsterländer → Small, nicht Large', $name($m) === 'Small Münsterländer');
$m = $zu(['name' => 'Irgendwas Ganz Anderes', 'name_en' => 'Toy Fox Terrier']);
pruefe('Englischer Name gewinnt → 100', $name($m) === 'Toy Fox Terrier' && $m['score'] === 100);
$m = $zu(['name' => 'Deutsche Dogge', 'thedogapi_id' => '9']);
pruefe('Vorhandene Zuordnung gewinnt → 100', $name($m) === 'Tibetan Mastiff' && $m['grund'] === 'schon zugeordnet');
$m = $zu(['name' => 'Bullmastiffartiger']);
pruefe('Nur ähnlich → nie sicher', $m === null || $m['score'] < TheDogApiAbgleich::SICHER);
pruefe('Nichts Ähnliches → kein Treffer', $zu(['name' => 'Qqqq Zzzz']) === null);

echo $fehler === 0 ? "\nAlle Tests grün.\n" : "\n{$fehler} Test(s) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
