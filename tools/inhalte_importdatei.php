<?php
/**
 * tools/inhalte_importdatei.php
 *
 * Vergleicht zwei Stände des Inhalte-Repos (PLAN.md Abschnitt 11) und
 * schreibt die Unterschiede als Importdatei für Verwaltung → „Inhalte
 * importieren“. Ohne Datenbank: Typen und Werte kommen aus merkmale.md.
 *
 * Aufruf (nur Kommandozeile):
 *   php tools/inhalte_importdatei.php <alt-ordner> <neu-ordner> <ausgabe.json>
 * alt = Stand des letzten Exports (entspricht live), neu = gemergter Stand.
 * Zwei Stände eines Repos z. B. so holen:
 *   git -C hunderassen-inhalte worktree add ../inhalte-alt <commit>
 *
 * Jede Änderung trägt den alten Wert mit. Die Importseite schreibt nur,
 * wo der Live-Wert noch diesem alten Wert entspricht.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/i18n.php';
require __DIR__ . '/../models/InhaltsDatei.php';

[, $altOrdner, $neuOrdner, $ausgabe] = $argv + [null, null, null, null];
if ($ausgabe === null) {
    exit("Aufruf: php {$argv[0]} <alt-ordner> <neu-ordner> <ausgabe.json>\n");
}

$fehler = [];

/** @return array{definitionen: array, rassen: array<int, array>} */
function stand_lesen(string $ordner, array &$fehler): array
{
    $datei = rtrim($ordner, '/\\') . '/merkmale.md';
    if (!is_file($datei)) {
        $fehler[] = "{$datei} fehlt";
        return ['definitionen' => [], 'rassen' => []];
    }
    $merkmale = InhaltsDatei::merkmaleLesen((string) file_get_contents($datei));
    array_push($fehler, ...$merkmale['fehler']);
    $rassen = [];
    foreach (glob(rtrim($ordner, '/\\') . '/rassen/*.md') ?: [] as $pfad) {
        $r = InhaltsDatei::rasseLesen((string) file_get_contents($pfad), $merkmale['definitionen'], InhaltsDatei::ABSCHNITTE);
        foreach ($r['fehler'] as $f) {
            $fehler[] = basename($pfad) . ': ' . $f;
        }
        if ($r['id'] > 0) {
            if (isset($rassen[$r['id']])) {
                $fehler[] = basename($pfad) . ": id {$r['id']} doppelt";
            }
            $r['datei'] = basename($pfad);
            $rassen[$r['id']] = $r;
        }
    }
    return ['definitionen' => $merkmale['definitionen'], 'rassen' => $rassen];
}

$alt = stand_lesen($altOrdner, $fehler);
$neu = stand_lesen($neuOrdner, $fehler);

// Werte: neue und umbenannte; Typen und Merkmale selbst sind fest
$werteAenderungen = [];
foreach ($neu['definitionen'] as $schluessel => $m) {
    $a = $alt['definitionen'][$schluessel] ?? null;
    if ($a === null) {
        $fehler[] = "merkmale.md: neues Merkmal „{$schluessel}“ – Merkmale bitte in der App anlegen";
        continue;
    }
    if ($a['typ'] !== $m['typ'] || $a['einheit'] !== $m['einheit'] || $a['je_geschlecht'] !== $m['je_geschlecht']) {
        $fehler[] = "merkmale.md: Typzeile von „{$schluessel}“ geändert";
    }
    foreach ($m['werte'] as $wert => $namen) {
        $vorher = $a['werte'][$wert] ?? null;
        if ($vorher !== $namen) {
            $werteAenderungen[] = ['merkmal' => $schluessel, 'schluessel' => $wert, 'alt' => $vorher, 'neu' => $namen];
        }
    }
}

$rassen = [];
foreach ($neu['rassen'] as $id => $r) {
    $a = $alt['rassen'][$id] ?? null;
    if ($a === null) {
        $fehler[] = "{$r['datei']}: Rasse {$id} gibt es im alten Stand nicht – neue Rassen bitte in der App anlegen";
        continue;
    }
    $aenderung = ['id' => $id, 'datei' => $r['datei'], 'merkmale' => [], 'texte' => []];
    if (trim($a['name_en']) !== trim($r['name_en'])) {
        $aenderung['name_en'] = ['alt' => trim($a['name_en']), 'neu' => trim($r['name_en'])];
    }
    foreach (array_keys($neu['definitionen']) as $schluessel) {
        $vorher = InhaltsDatei::wertNorm($a['merkmale'][$schluessel] ?? null);
        $nachher = InhaltsDatei::wertNorm($r['merkmale'][$schluessel] ?? null);
        if ($vorher !== $nachher) {
            $aenderung['merkmale'][$schluessel] = ['alt' => $vorher, 'neu' => $nachher];
        }
    }
    foreach (['de', 'en'] as $sprache) {
        foreach (array_keys(InhaltsDatei::ABSCHNITTE) as $abschnitt) {
            $vorher = InhaltsDatei::textNorm($a['texte'][$sprache][$abschnitt] ?? null);
            $nachher = InhaltsDatei::textNorm($r['texte'][$sprache][$abschnitt] ?? null);
            if ($vorher !== $nachher) {
                $aenderung['texte'][$sprache][$abschnitt] = ['alt' => $vorher, 'neu' => $nachher];
            }
        }
    }
    if (isset($aenderung['name_en']) || $aenderung['merkmale'] || $aenderung['texte']) {
        $rassen[] = $aenderung;
    }
}

if ($fehler) {
    fwrite(STDERR, "Nicht erzeugt, bitte erst beheben:\n- " . implode("\n- ", $fehler) . "\n");
    exit(1);
}

file_put_contents($ausgabe, json_encode([
    'format' => 1,
    'erzeugt' => date('c'),
    'werte' => $werteAenderungen,
    'rassen' => $rassen,
], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) . "\n");

$felder = array_sum(array_map(fn($r) => count($r['merkmale']) + array_sum(array_map('count', $r['texte'])) + (isset($r['name_en']) ? 1 : 0), $rassen));
echo count($rassen) . " Rassen mit {$felder} Änderungen, " . count($werteAenderungen) . " Werte neu oder umbenannt → {$ausgabe}\n";
