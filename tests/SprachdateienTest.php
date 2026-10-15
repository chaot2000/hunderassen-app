<?php
/**
 * tests/SprachdateienTest.php
 *
 * Prüft lang/de.php und lang/en.php sowie config/i18n.php ohne
 * Datenbank. Aufruf: php tests/SprachdateienTest.php, Exit-Code 0 = grün.
 */

declare(strict_types=1);

require __DIR__ . '/../config/i18n.php';

$fehler = 0;
function pruefe(string $beschreibung, bool $ok): void
{
    global $fehler;
    echo ($ok ? 'OK   ' : 'FAIL ') . $beschreibung . "\n";
    if (!$ok) {
        $fehler++;
    }
}

/** @return list<string> */
function platzhalter(string $text): array
{
    preg_match_all('/\{([a-z_]+)\}/', $text, $m);
    $namen = $m[1];
    sort($namen);
    return $namen;
}

$de = sprachtexte('de');
$en = sprachtexte('en');

pruefe('Deutsche Texte geladen', count($de) > 20);
pruefe('Englische Texte geladen', count($en) > 20);

$nurDe = array_diff(array_keys($de), array_keys($en));
$nurEn = array_diff(array_keys($en), array_keys($de));
pruefe('Jeder deutsche Schlüssel hat einen englischen' . ($nurDe ? ': fehlt ' . implode(', ', $nurDe) : ''), $nurDe === []);
pruefe('Kein englischer Schlüssel ohne deutschen' . ($nurEn ? ': ' . implode(', ', $nurEn) : ''), $nurEn === []);

foreach ($de as $schluessel => $text) {
    if (trim($text) === '' || trim($en[$schluessel] ?? 'x') === '') {
        pruefe("Text für {$schluessel} ist nicht leer", false);
    }
    if (isset($en[$schluessel]) && platzhalter($text) !== platzhalter($en[$schluessel])) {
        pruefe("Platzhalter in {$schluessel} gleich", false);
    }
}

// t(): Platzhalter, Rückfall, unbekannter Schlüssel
pruefe('t() setzt Platzhalter ein', t('konto.angemeldet_als', ['name' => 'Bello'], 'de') === 'Angemeldet als Bello');
pruefe('t() auf Englisch', t('nav.rassen', [], 'en') === 'Breeds');
pruefe('Unbekannter Schlüssel bleibt sichtbar', t('gibt.es.nicht', [], 'en') === 'gibt.es.nicht');
pruefe('Unbekannte Sprache fällt auf Deutsch zurück', t('nav.rassen', [], 'xx') === 'Rassen');

// browser_sprache()
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en-US,en;q=0.9,de;q=0.8';
pruefe('Accept-Language: Englisch zuerst', browser_sprache() === 'en');
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr-FR,fr;q=0.9,de;q=0.7,en;q=0.5';
pruefe('Accept-Language: nicht unterstützte überspringen', browser_sprache() === 'de');
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'de;q=0.2, en;q=0.8';
pruefe('Accept-Language: Gewichtung zählt', browser_sprache() === 'en');
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'fr, es';
pruefe('Accept-Language: nichts Passendes', browser_sprache() === null);
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = '<script>, ../../etc';
pruefe('Accept-Language: Müll wird ignoriert', browser_sprache() === null);

// aktuelle_sprache(): Benutzer vor Session vor Browser
$_SESSION = [];
$_SERVER['HTTP_ACCEPT_LANGUAGE'] = 'en';
pruefe('Ohne Session gilt der Browser', aktuelle_sprache() === 'en');
$_SESSION['sprache'] = 'de';
pruefe('Auswahl in der Session schlägt den Browser', aktuelle_sprache() === 'de');
$_SESSION['user'] = ['sprache' => 'en'];
pruefe('Benutzer-Einstellung schlägt die Session', aktuelle_sprache() === 'en');
$_SESSION['user'] = ['sprache' => 'xx'];
pruefe('Ungültiger Wert wird übersprungen', aktuelle_sprache() === 'de');
$_SESSION = [];
unset($_SERVER['HTTP_ACCEPT_LANGUAGE']);
pruefe('Ganz ohne Angaben: Deutsch', aktuelle_sprache() === 'de');

echo $fehler === 0 ? "\nAlle Tests grün.\n" : "\n{$fehler} Test(s) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
