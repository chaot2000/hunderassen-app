<?php
/**
 * tests/WikimediaBilderTest.php
 *
 * Tests für die Auswertung von Wikidata und Commons (models/WikimediaBilder.php)
 * und den Bildnachweis (RassenBild::nachweis). Ohne Netz und ohne Datenbank.
 * Aufruf: php tests/WikimediaBilderTest.php, Exit-Code 0 = alles grün.
 */

declare(strict_types=1);

require __DIR__ . '/../models/WikimediaBilder.php';
require __DIR__ . '/../models/RassenBild.php';

$fehler = 0;
function pruefe(string $beschreibung, bool $ok): void
{
    global $fehler;
    echo ($ok ? 'OK   ' : 'FAIL ') . $beschreibung . "\n";
    if (!$ok) {
        $fehler++;
    }
}

// Lizenzen
foreach (['CC BY-SA 3.0', 'CC BY 2.0', 'CC BY-SA 4.0', 'CC0', 'Public domain', 'PD-old'] as $l) {
    pruefe("Lizenz erlaubt: {$l}", WikimediaBilder::lizenzErlaubt($l));
}
foreach (['CC BY-NC 2.0', 'CC BY-NC-SA 4.0', 'CC BY-ND 3.0', 'GFDL', 'Fair use', ''] as $l) {
    pruefe("Lizenz abgelehnt: '{$l}'", !WikimediaBilder::lizenzErlaubt($l));
}

// Urheber aus HTML
pruefe('Urheber ohne HTML', WikimediaBilder::urheber('<a href="//commons.wikimedia.org/wiki/User:X">Томасина</a>') === 'Томасина');
pruefe('Urheber mit Entitäten und Zeilen', WikimediaBilder::urheber("Photo by\n <b>A &amp; B</b>") === 'Photo by A & B');
pruefe('Leerer Urheber', WikimediaBilder::urheber('  ') === 'Unbekannt');

// IDs und Adressen
pruefe('Q-ID gültig', WikimediaBilder::istQid('Q21080'));
pruefe('Q-ID ungültig', !WikimediaBilder::istQid('Q0') && !WikimediaBilder::istQid('21080') && !WikimediaBilder::istQid('Q1|Q2'));
pruefe('Wikimedia-Adresse', WikimediaBilder::istWikimediaUrl('https://upload.wikimedia.org/x.jpg'));
pruefe('Fremde Adresse', !WikimediaBilder::istWikimediaUrl('https://wikimedia.org.example.com/x.jpg') && !WikimediaBilder::istWikimediaUrl('http://upload.wikimedia.org/x.jpg'));
pruefe('Laden von fremdem Host abgelehnt', WikimediaBilder::laden('https://example.com/x.jpg')['success'] === false);

// Titel aus Name und alternativen Namen
$t = WikimediaBilder::titel('Galgo Espanol, Spanischer Windhund', 'Spanish Greyhound; Galgo (Spanien)');
pruefe('Titel: ganzer Name', in_array('Galgo Espanol, Spanischer Windhund', $t, true));
pruefe('Titel: Teile', in_array('Galgo Espanol', $t, true) && in_array('Spanischer Windhund', $t, true));
pruefe('Titel: ohne Klammerzusatz', in_array('Galgo', $t, true));

// wbgetentities
$entities = ['entities' => [
    'Q21080' => ['id' => 'Q21080', 'labels' => ['de' => ['value' => 'Afghanischer Windhund']], 'descriptions' => ['de' => ['value' => 'Hunderasse']],
        'claims' => [
            'P31' => [['mainsnak' => ['datavalue' => ['value' => ['id' => 'Q39367']]]]],
            'P18' => [['mainsnak' => ['datavalue' => ['value' => 'Afghan Hound in Tallinn.JPG']]]],
            'P373' => [['mainsnak' => ['datavalue' => ['value' => 'Afghan Hound']]]],
        ],
        'sitelinks' => ['dewiki' => ['title' => 'Afghanischer Windhund']]],
    '-1' => ['missing' => ''],
    'Q5' => ['id' => 'Q5', 'labels' => ['en' => ['value' => 'human']], 'claims' => []],
]];
$e = WikimediaBilder::entitaetenAuswerten($entities);
pruefe('Fehlender Eintrag fällt weg', count($e) === 2);
pruefe('Hunderasse erkannt', $e['Q21080']['rasse'] === true && $e['Q5']['rasse'] === false);
pruefe('Hauptbild, Kategorie, Artikel', $e['Q21080']['bilder'] === ['Afghan Hound in Tallinn.JPG'] && $e['Q21080']['kategorie'] === 'Afghan Hound' && $e['Q21080']['wikipedia_de'] === 'Afghanischer Windhund');
pruefe('Englische Bezeichnung als Rückfall', $e['Q5']['label'] === 'human');

// Commons-Bildinfo
$seite = fn(string $name, string $mime, string $lizenz, string $url) => ['title' => 'File:' . $name, 'imageinfo' => [[
    'mime' => $mime, 'width' => 2000, 'height' => 1500, 'thumburl' => $url, 'descriptionurl' => 'https://commons.wikimedia.org/wiki/File:' . $name,
    'extmetadata' => ['Artist' => ['value' => '<a href="#">Fotograf</a>'], 'LicenseShortName' => ['value' => $lizenz], 'LicenseUrl' => ['value' => 'https://creativecommons.org/licenses/by-sa/4.0']],
]]];
$bilder = WikimediaBilder::auswerten([
    $seite('Hund.jpg', 'image/jpeg', 'CC BY-SA 4.0', 'https://upload.wikimedia.org/wikipedia/commons/thumb/a/ab/Hund.jpg/1280px-Hund.jpg'),
    $seite('Haupt.jpg', 'image/jpeg', 'CC0', 'https://upload.wikimedia.org/wikipedia/commons/thumb/c/cd/Haupt.jpg/1280px-Haupt.jpg'),
    $seite('Hund.jpg', 'image/jpeg', 'CC BY-SA 4.0', 'https://upload.wikimedia.org/doppelt.jpg'),
    $seite('Bellen.ogg', 'application/ogg', 'CC BY 3.0', 'https://upload.wikimedia.org/b.ogg'),
    $seite('Skizze.svg', 'image/svg+xml', 'CC0', 'https://upload.wikimedia.org/s.svg'),
    $seite('Unfrei.jpg', 'image/jpeg', 'CC BY-NC 2.0', 'https://upload.wikimedia.org/u.jpg'),
    $seite('Fremd.jpg', 'image/jpeg', 'CC0', 'https://example.com/f.jpg'),
], ['Haupt.jpg']);
pruefe('Nur freie Fotos von Wikimedia, jedes einmal', array_column($bilder, 'id') === ['Haupt.jpg', 'Hund.jpg']);
pruefe('Hauptbild vorne und markiert', $bilder[0]['haupt'] === true && $bilder[1]['haupt'] === false);
pruefe('Vorschau in 330 px', str_contains($bilder[1]['vorschau'], '/330px-Hund.jpg'));
pruefe('Nachweis aus den Metadaten', $bilder[1]['urheber'] === 'Fotograf' && $bilder[1]['lizenz'] === 'CC BY-SA 4.0' && $bilder[1]['seite'] !== '');

// Bildnachweis
$n = RassenBild::nachweis(['bild_quelle' => 'Wikimedia:Afghan Hound in Tallinn.JPG', 'bild_urheber' => 'Томасина', 'bild_lizenz' => 'CC BY-SA 3.0', 'bild_lizenz_url' => 'https://creativecommons.org/licenses/by-sa/3.0']);
pruefe('Nachweis Wikimedia mit Link zur Bildseite', $n['link'] === 'https://commons.wikimedia.org/wiki/File:Afghan_Hound_in_Tallinn.JPG' && $n['quelle'] === 'Wikimedia Commons');
pruefe('Nachweis als Text', RassenBild::nachweisText(['bild_quelle' => 'Upload', 'bild_urheber' => 'Eigenes Foto']) === 'Foto: Eigenes Foto');
pruefe('Kein Nachweis ohne Angaben', RassenBild::nachweis(['bild_quelle' => 'Upload', 'bild_urheber' => '']) === null);
pruefe('Herkunftsart', RassenBild::quellenArt('Wikimedia:x.jpg') === 'wikimedia' && RassenBild::quellenArt('TheDogAPI:abc') === 'thedogapi' && RassenBild::quellenArt(null) === '');

echo $fehler === 0 ? "\nAlle Tests grün.\n" : "\n{$fehler} Test(s) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
