<?php
/**
 * tests/RassenDatenTest.php
 *
 * Tests für die datenbankfreien Teile von models/RassenDaten.php:
 * Zerlegen alter Texte in Abschnitte, Länder.
 * Aufruf: php tests/RassenDatenTest.php, Exit-Code 0 = alles grün.
 */

declare(strict_types=1);

require __DIR__ . '/../config/i18n.php';
require __DIR__ . '/../models/RassenDaten.php';

$fehler = 0;
function pruefe(string $beschreibung, bool $ok): void
{
    global $fehler;
    echo ($ok ? 'OK   ' : 'FAIL ') . $beschreibung . "\n";
    if (!$ok) {
        $fehler++;
    }
}

// Gegliederter deutscher Text
$t = RassenDaten::textZerlegen("Ein kleiner Hund.\n\nNoch ein Satz ohne Überschrift.\n\n## Wesen\n\nFreundlich.\n\n## Pflege\n\nBürsten.\n\n## Eigene Überschrift\n\nEtwas Besonderes.", false);
pruefe('Kurzporträt = erster Absatz', ($t['kurzportraet'] ?? '') === 'Ein kleiner Hund.');
pruefe('Absatz ohne Überschrift → allgemein', ($t['allgemein'] ?? '') === 'Noch ein Satz ohne Überschrift.');
pruefe('Wesen erkannt', ($t['wesen'] ?? '') === 'Freundlich.');
pruefe('Pflege erkannt', ($t['pflege'] ?? '') === 'Bürsten.');
pruefe('Unbekannte Überschrift → weitere, mit Überschrift', ($t['weitere'] ?? '') === "## Eigene Überschrift\n\nEtwas Besonderes.");

// Englische Überschriften
$t = RassenDaten::textZerlegen("Short intro.\n\n## Character\n\nCalm.\n\n## Origin and history\n\nOld breed.", false);
pruefe('Englisch: Character → wesen', ($t['wesen'] ?? '') === 'Calm.');
pruefe('Englisch: Origin and history → herkunft', ($t['herkunft'] ?? '') === 'Old breed.');

// TheDogAPI-Import: Beschreibung, Leerzeile, Geschichte
$t = RassenDaten::textZerlegen("Medium-sized water dog.\n\nAncient Dutch breed.", true);
pruefe('Import: Beschreibung → Kurzporträt', ($t['kurzportraet'] ?? '') === 'Medium-sized water dog.');
pruefe('Import: Geschichte → herkunft', ($t['herkunft'] ?? '') === 'Ancient Dutch breed.' && !isset($t['allgemein']));
$t = RassenDaten::textZerlegen("Medium-sized water dog.\n\nSecond paragraph.", false);
pruefe('Kein Import: zweiter Absatz → allgemein', ($t['allgemein'] ?? '') === 'Second paragraph.');

pruefe('Leerer Text → nichts', RassenDaten::textZerlegen('', false) === []);

// Länder
pruefe('Deutschland → DE', RassenDaten::landCode('Deutschland') === 'DE');
pruefe('Germany → DE', RassenDaten::landCode('Germany') === 'DE');
pruefe('England → GB (Alias)', RassenDaten::landCode('England') === 'GB');
pruefe('Code bleibt Code', RassenDaten::landCode('fr') === 'FR');
pruefe('Unbekannt → null', RassenDaten::landCode('Atlantis') === null);
pruefe('Name deutsch', RassenDaten::landName('HU', 'de') === 'Ungarn');
pruefe('Name englisch', RassenDaten::landName('HU', 'en') === 'Hungary');

// Abschnitte und Übersetzungen passen zusammen
foreach (RassenDaten::ABSCHNITTE as $abschnitt => $titel) {
    if ($titel !== null && rassentext_titel_vorhanden($titel) === false) {
        pruefe("Titel „{$titel}“ hat eine Übersetzung", false);
    }
}
function rassentext_titel_vorhanden(string $titel): bool
{
    $de = sprachtexte('de');
    return in_array($titel, $de, true);
}
pruefe('Alle Abschnittstitel stehen in lang/de.php', true);

echo $fehler === 0 ? "\nAlle Tests grün.\n" : "\n{$fehler} Test(s) fehlgeschlagen.\n";
exit($fehler === 0 ? 0 : 1);
