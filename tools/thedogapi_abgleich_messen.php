<?php
/**
 * tools/thedogapi_abgleich_messen.php
 *
 * Misst die Zuordnung VDH ↔ TheDogAPI gegen eine Datenbank und die
 * zwischengespeicherte API-Antwort, ohne etwas zu schreiben.
 *
 * Aufruf (nur Kommandozeile):
 *   php tools/thedogapi_abgleich_messen.php <db-name> [cache.json] [ausgabe.tsv]
 * Zugang: Umgebungsvariablen DB_HOST, DB_USER, DB_PASS (Standard
 * 127.0.0.1, root, leer, passend zu Laragon).
 *
 * OHNE_ZUORDNUNG=1 misst ohne die vorhandenen thedogapi_id und listet
 * vorhandene Zuordnungen, die vom berechneten Treffer abweichen.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../models/TheDogApiAbgleich.php';

$db = $argv[1] ?? exit("Aufruf: php {$argv[0]} <db-name> [cache.json] [ausgabe.tsv]\n");
$cache = $argv[2] ?? __DIR__ . '/../cache_thedogapi_breeds.json';
$ausgabe = $argv[3] ?? null;

$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4',
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
$spalten = array_column($pdo->query('SHOW COLUMNS FROM breeds')->fetchAll(), 'Field');
$felder = array_intersect(['id', 'name', 'name_en', 'alternative_namen', 'thedogapi_id'], $spalten);
$rassen = $pdo->query('SELECT ' . implode(', ', $felder) . ' FROM breeds ORDER BY name')->fetchAll();
$api = json_decode((string) file_get_contents($cache), true) ?: exit("Cache nicht lesbar: {$cache}\n");

$zaehler = ['sicher' => 0, 'unsicher' => 0, 'keiner' => 0];
$gruende = [];
$doppelt = [];
$zeilen = [];
$ohneZuordnung = getenv('OHNE_ZUORDNUNG') === '1';
$abweichend = [];
$apiNachId = array_column($api, 'name', 'id');
foreach ($rassen as $r) {
    $vorhanden = $r['thedogapi_id'] ?? null;
    if ($ohneZuordnung) {
        $r['thedogapi_id'] = null;
    }
    $m = TheDogApiAbgleich::bestesMatch($r, $api);
    if ($ohneZuordnung && $vorhanden && (string) ($m['breed']['id'] ?? '') !== (string) $vorhanden
        && TheDogApiAbgleich::aufbereiten((string) ($m['breed']['name'] ?? '')) !== TheDogApiAbgleich::aufbereiten((string) ($apiNachId[$vorhanden] ?? ''))) {
        $abweichend[] = sprintf('  %-45s bisher: %-30s berechnet: %s (%d)', $r['name'], $apiNachId[$vorhanden] ?? $vorhanden, $m['breed']['name'] ?? '-', $m['score'] ?? 0);
    }
    $band = $m === null ? 'keiner' : ($m['score'] >= TheDogApiAbgleich::SICHER ? 'sicher' : 'unsicher');
    $zaehler[$band]++;
    $grund = $m['grund'] ?? '-';
    $gruende[$grund] = ($gruende[$grund] ?? 0) + 1;
    if ($band === 'sicher') {
        $doppelt[$m['breed']['name']][] = $r['name'];
    }
    $zeilen[] = implode("\t", [$r['id'], $r['name'], $m['breed']['name'] ?? '', $m['score'] ?? 0, $grund]);
}

echo count($rassen) . " Rassen, " . count($api) . " TheDogAPI-Rassen\n";
foreach ($zaehler as $k => $v) {
    echo str_pad($k, 10) . $v . "\n";
}
echo "\nnach Grund:\n";
arsort($gruende);
foreach ($gruende as $k => $v) {
    echo '  ' . str_pad($k, 20) . $v . "\n";
}
$mehrfach = array_filter($doppelt, fn($l) => count($l) > 1);
if ($mehrfach) {
    echo "\nTheDogAPI-Rasse mehrfach sicher zugeordnet (Varianten oder Fehler):\n";
    foreach ($mehrfach as $apiName => $vdh) {
        echo "  {$apiName}: " . implode(' | ', $vdh) . "\n";
    }
}
$apiNamen = array_flip(array_map([TheDogApiAbgleich::class, 'aufbereiten'], array_column($api, 'name')));
$fehlend = array_filter(TheDogApiAbgleich::MANUELL, fn($ziel) => !isset($apiNamen[TheDogApiAbgleich::aufbereiten($ziel)]));
if ($fehlend) {
    echo "\nFeste Zuordnung zeigt auf unbekannte TheDogAPI-Rasse:\n";
    foreach ($fehlend as $vdh => $ziel) {
        echo "  {$vdh} → {$ziel}\n";
    }
}
if ($abweichend) {
    echo "
Vorhandene Zuordnung weicht ab (" . count($abweichend) . "):
" . implode("
", $abweichend) . "
";
}
if ($ausgabe !== null) {
    file_put_contents($ausgabe, implode("\n", $zeilen) . "\n");
}
