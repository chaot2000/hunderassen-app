<?php
/**
 * tools/inhalte_export.php
 *
 * Schreibt die Rasseninhalte einer Datenbank ins Dateiformat des
 * privaten Inhalte-Repos (models/InhaltsDatei.php, PLAN.md Abschnitt 11):
 * rassen/NNN-name.md je Rasse und merkmale.md. Liest nur, schreibt nichts
 * in die Datenbank.
 *
 * Aufruf (nur Kommandozeile), z. B. mit einer eingespielten Live-Sicherung:
 *   php tools/inhalte_export.php <db-name> <ziel-ordner>
 * Zugang: Umgebungsvariablen DB_HOST, DB_USER, DB_PASS (Standard
 * 127.0.0.1, root, leer, passend zu Laragon).
 *
 * Der Zielordner ist der Klon von chaot2000/hunderassen-inhalte. Inhalte
 * gehören nie ins öffentliche App-Repo.
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(404);
    exit;
}

require __DIR__ . '/../config/database.php';
require __DIR__ . '/../config/i18n.php';
require __DIR__ . '/../models/RassenDatenSchreiben.php';
require __DIR__ . '/../models/InhaltsDatei.php';

$db = $argv[1] ?? exit("Aufruf: php {$argv[0]} <db-name> <ziel-ordner>\n");
$ziel = rtrim($argv[2] ?? exit("Aufruf: php {$argv[0]} <db-name> <ziel-ordner>\n"), '/\\');
if (!is_dir($ziel)) {
    exit("Zielordner {$ziel} fehlt.\n");
}

$pdo = new PDO(
    'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4',
    getenv('DB_USER') ?: 'root',
    getenv('DB_PASS') ?: '',
    [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
);
// Die Models holen ihre Verbindung über Database::getConnection()
$instanz = new ReflectionProperty(Database::class, 'instance');
$instanz->setValue(null, $pdo);

$offen = (int) $pdo->query('SELECT COUNT(*) FROM breeds WHERE daten_stand IS NULL')->fetchColumn();
if ($offen > 0) {
    exit("{$offen} Rassen sind noch nicht ins neue Modell übernommen. Erst „Rassendaten übernehmen“ (live) laufen lassen.\n");
}

$definitionen = RassenDatenSchreiben::definitionen(true);
@mkdir($ziel . '/rassen');
foreach (glob($ziel . '/rassen/*.md') ?: [] as $alt) {
    unlink($alt);   // gelöschte Rassen sollen verschwinden
}

$n = 0;
foreach ($pdo->query('SELECT id, name, name_en FROM breeds ORDER BY id')->fetchAll() as $rasse) {
    $id = (int) $rasse['id'];
    $md = InhaltsDatei::rasseSchreiben($rasse, RassenDatenSchreiben::formularWerte($id), RassenDaten::alleTexte($id),
        $definitionen, InhaltsDatei::ABSCHNITTE);
    file_put_contents($ziel . '/rassen/' . InhaltsDatei::dateiname($id, $rasse['name']), $md);
    $n++;
}
file_put_contents($ziel . '/merkmale.md', InhaltsDatei::merkmaleSchreiben($definitionen));

echo "{$n} Rassen und merkmale.md nach {$ziel} geschrieben.\n";
