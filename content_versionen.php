<?php
/**
 * content_versionen.php
 *
 * Versionen der Texte einer Rasse (?typ=rasse&id=…) oder eines Tests
 * (?typ=test&id=…): jede gespeicherte Änderung mit Vorher/Nachher und
 * Wortvergleich, dazu "Stand vor dieser Änderung wiederherstellen" pro
 * Feld. Bei Rassen zusätzlich "Originaltext wiederherstellen"
 * (breeds.beschreibung_original).
 *
 * Rechte: Rassen = can_manage_breeds (geteilter Katalog), Tests =
 * can_manage_tests und nur Versionen des eigenen Mandanten.
 * Funktioniert auch für gelöschte Datensätze (Versionen bleiben).
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/ContentRevision.php';
require_once __DIR__ . '/models/Breed.php';
require_once __DIR__ . '/models/Test.php';

secure_session_start();

$typ = $_GET['typ'] ?? '';
$id  = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($typ === 'rasse') {
    require_manage_breeds();
    $tabelle   = 'breeds';
    $tenantId  = null;
    $datensatz = $id > 0 ? Breed::findById($id) : null;
    $zurueck   = $datensatz ? '/admin_add_breed.php?id=' . $id : '/rassen.php';
} elseif ($typ === 'test') {
    require_manage_tests();
    $tabelle   = 'tests';
    $tenantId  = current_tenant_id();
    $datensatz = $id > 0 ? Test::findById($id, $tenantId) : null;
    $zurueck   = $datensatz ? '/test_form.php?id=' . $id : '/admin_manage_tests.php';
} else {
    http_response_code(404);
    die('Unbekannter Typ.');
}

$meldung = null;
$fehler  = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $userId = (int) current_user()['id'];

    if (isset($_POST['revision_id'])) {
        $result = ContentRevision::wiederherstellen((int) $_POST['revision_id'], $tabelle, $id, $tenantId, $userId);
    } elseif (isset($_POST['original']) && $tabelle === 'breeds') {
        $result = ContentRevision::originalWiederherstellen($id, $userId);
    } else {
        $result = ['success' => false, 'message' => 'Unbekannte Aktion.'];
    }

    // PRG: Meldung über die Session, damit ein Neuladen nichts doppelt ausführt.
    $_SESSION['versionen_meldung'] = $result;
    header('Location: /content_versionen.php?typ=' . urlencode($typ) . '&id=' . $id);
    exit;
}

if (isset($_SESSION['versionen_meldung'])) {
    $r = $_SESSION['versionen_meldung'];
    unset($_SESSION['versionen_meldung']);
    if ($r['success']) {
        $meldung = $r['message'];
    } else {
        $fehler = $r['message'];
    }
}

$versionen = ContentRevision::fuerDatensatz($tabelle, $id, $tenantId);

if ($datensatz === null && empty($versionen)) {
    http_response_code(404);
    die($typ === 'rasse' ? 'Die angeforderte Rasse wurde nicht gefunden.' : 'Der angeforderte Test wurde nicht gefunden.');
}

// Titel: aktueller Name, bei gelöschten Datensätzen der letzte bekannte.
$titel = $datensatz['name'] ?? null;
if ($titel === null) {
    foreach ($versionen as $v) {
        if ($v['feld'] === 'name') {
            $titel = $v['alter_wert'] ?? $v['neuer_wert'];
            break;
        }
    }
}
$titel = $titel ?? ('#' . $id);

// Originaltext (nur Rassen): nur anbieten, wenn er vom aktuellen abweicht
// und die Rasse noch nicht ins Merkmalsmodell übernommen ist. Danach
// stehen die Texte in Abschnitten und werden je Abschnitt wiederhergestellt.
$original = null;
if ($tabelle === 'breeds' && $datensatz !== null) {
    $pdo = Database::getConnection();
    $st = $pdo->prepare('SELECT beschreibung, beschreibung_original FROM breeds WHERE id = :id AND daten_stand IS NULL');
    $st->execute([':id' => $id]);
    $row = $st->fetch();
    if ($row && $row['beschreibung_original'] !== null && $row['beschreibung_original'] !== $row['beschreibung']) {
        $original = $row;
    }
}

// Versionen zu Änderungsvorgängen bündeln (gleicher Zeitpunkt, Anlass, Person).
$vorgaenge = [];
foreach ($versionen as $v) {
    $key = $v['geaendert_am'] . '|' . $v['anlass'] . '|' . ($v['username'] ?? '');
    $vorgaenge[$key]['kopf'] ??= $v;
    $vorgaenge[$key]['felder'][] = $v;
}


/**
 * Wortgenauer Vergleich als HTML mit <del>/<ins>. Gemeinsamer Anfang
 * und gemeinsames Ende werden vorab abgeschnitten (Änderungen sind
 * meist lokal); ist der Rest zu groß für den LCS-Vergleich, wird er als
 * Block ersetzt angezeigt.
 */
function render_wortdiff(?string $alt, ?string $neu): string
{
    $a = preg_split('/(\s+)/u', $alt ?? '', -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];
    $b = preg_split('/(\s+)/u', $neu ?? '', -1, PREG_SPLIT_DELIM_CAPTURE | PREG_SPLIT_NO_EMPTY) ?: [];

    $start = 0;
    while ($start < count($a) && $start < count($b) && $a[$start] === $b[$start]) {
        $start++;
    }
    $endeA = count($a);
    $endeB = count($b);
    while ($endeA > $start && $endeB > $start && $a[$endeA - 1] === $b[$endeB - 1]) {
        $endeA--;
        $endeB--;
    }

    $kopf = implode('', array_slice($a, 0, $start));
    $fuss = implode('', array_slice($a, $endeA));
    $mitteA = array_slice($a, $start, $endeA - $start);
    $mitteB = array_slice($b, $start, $endeB - $start);

    $html = e(kuerze_kontext($kopf, true));

    if (count($mitteA) * count($mitteB) > 400000) {
        if ($mitteA) {
            $html .= '<del>' . e(implode('', $mitteA)) . '</del>';
        }
        if ($mitteB) {
            $html .= '<ins>' . e(implode('', $mitteB)) . '</ins>';
        }
    } else {
        $n = count($mitteA);
        $m = count($mitteB);
        $lcs = array_fill(0, $n + 1, array_fill(0, $m + 1, 0));
        for ($i = $n - 1; $i >= 0; $i--) {
            for ($j = $m - 1; $j >= 0; $j--) {
                $lcs[$i][$j] = $mitteA[$i] === $mitteB[$j]
                    ? $lcs[$i + 1][$j + 1] + 1
                    : max($lcs[$i + 1][$j], $lcs[$i][$j + 1]);
            }
        }
        $i = $j = 0;
        $del = $ins = '';
        $flush = function () use (&$html, &$del, &$ins) {
            if ($del !== '') {
                $html .= '<del>' . e($del) . '</del>';
                $del = '';
            }
            if ($ins !== '') {
                $html .= '<ins>' . e($ins) . '</ins>';
                $ins = '';
            }
        };
        while ($i < $n || $j < $m) {
            if ($i < $n && $j < $m && $mitteA[$i] === $mitteB[$j]) {
                $flush();
                $html .= e($mitteA[$i]);
                $i++;
                $j++;
            } elseif ($j < $m && ($i >= $n || $lcs[$i][$j + 1] >= $lcs[$i + 1][$j])) {
                $ins .= $mitteB[$j++];
            } else {
                $del .= $mitteA[$i++];
            }
        }
        $flush();
    }

    return $html . e(kuerze_kontext($fuss, false));
}

/**
 * Langen unveränderten Kontext auf ~200 Zeichen kürzen, damit die
 * Änderung im Blick bleibt.
 */
function kuerze_kontext(string $text, bool $vorne): string
{
    if (mb_strlen($text) <= 240) {
        return $text;
    }
    return $vorne ? '… ' . mb_substr($text, -200) : mb_substr($text, 0, 200) . ' …';
}

function ist_langer_text(?string $a, ?string $b): bool
{
    return mb_strlen((string) $a) > 120 || mb_strlen((string) $b) > 120
        || str_contains((string) $a, "\n") || str_contains((string) $b, "\n");
}

$anlassFarben = [
    'bearbeitung'       => 'bg-tanne/10 text-tanne',
    'import'            => 'bg-sand text-fellDk',
    'loeschung'         => 'bg-red-50 text-red-800',
    'wiederherstellung' => 'bg-fell/10 text-fell',
];

$pageTitle = 'Versionen: ' . $titel;
require __DIR__ . '/views/partials/header.php';
?>

<style>
    .wortdiff del { background: #fde2e2; color: #7f1d1d; text-decoration: line-through; text-decoration-thickness: 1px; }
    .wortdiff ins { background: #dcefd8; color: #1f3d20; text-decoration: none; }
</style>

<a href="<?= e($zurueck) ?>" class="inline-block text-sm text-tanne hover:text-tanneDk font-semibold underline-offset-2 hover:underline mb-4">
    ← <?= $datensatz ? ($typ === 'rasse' ? 'Zurück zur Rasse' : 'Zurück zum Test') : 'Zurück zur Übersicht' ?>
</a>

<h1 class="text-2xl font-extrabold text-fellDk">Versionen: <?= e($titel) ?></h1>
<p class="text-sm text-fellDk/70 mt-1 mb-6">
    Jede gespeicherte Änderung an den Texten, neueste zuerst. „Wiederherstellen“ setzt ein Feld auf den Stand vor der jeweiligen Änderung zurück. Auch das wird wieder als Version gespeichert.
</p>

<?php if ($datensatz === null): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-lg px-4 py-3 mb-6" role="status">
        Dieser Datensatz wurde gelöscht. Die Texte unten lassen sich nachlesen und kopieren, aber nicht mehr per Knopf wiederherstellen.
    </div>
<?php endif; ?>

<?php if ($meldung !== null): ?>
    <div class="bg-tanne/10 border border-tanne/30 text-tanneDk text-sm rounded-lg px-4 py-3 mb-6" role="status"><?= e($meldung) ?></div>
<?php endif; ?>
<?php if ($fehler !== null): ?>
    <div class="bg-red-50 border border-red-200 text-red-800 text-sm rounded-lg px-4 py-3 mb-6" role="alert"><?= e($fehler) ?></div>
<?php endif; ?>

<?php if ($original !== null): ?>
    <section class="bg-white/80 rounded-2xl border border-sand p-5 mb-6" aria-labelledby="original-titel">
        <div class="flex flex-wrap items-start justify-between gap-3">
            <div>
                <h2 id="original-titel" class="text-lg font-bold text-fellDk">Originaltext</h2>
                <p class="text-sm text-fellDk/70">Stand beim Anlegen bzw. bei Einführung der Versionierung. Die aktuelle Beschreibung weicht davon ab.</p>
            </div>
            <form method="post" onsubmit="return confirm('Beschreibung auf den Originaltext zurücksetzen? Der aktuelle Text bleibt als Version erhalten.');">
                <?= csrf_field() ?>
                <input type="hidden" name="original" value="1">
                <button type="submit" class="bg-tanne hover:bg-tanneDk text-creme font-bold text-sm px-4 py-2 rounded-lg transition-colors">
                    Original wiederherstellen
                </button>
            </form>
        </div>
        <details class="mt-3">
            <summary class="text-sm font-semibold text-tanne cursor-pointer">Unterschied zur aktuellen Beschreibung anzeigen</summary>
            <div class="wortdiff mt-3 text-sm leading-relaxed whitespace-pre-line text-fellDk"><?= render_wortdiff($original['beschreibung'], $original['beschreibung_original']) ?></div>
        </details>
    </section>
<?php endif; ?>

<?php if (empty($vorgaenge)): ?>
    <div class="bg-white/80 rounded-2xl border border-sand px-5 py-10 text-center text-fellDk/70">
        Noch keine Änderungen gespeichert. Ab jetzt wird jede Änderung an den Texten hier festgehalten.
    </div>
<?php else: ?>
    <ol class="space-y-4">
        <?php foreach ($vorgaenge as $vorgang): $k = $vorgang['kopf']; ?>
            <li class="bg-white/80 rounded-2xl border border-sand p-5">
                <div class="flex flex-wrap items-center gap-x-3 gap-y-1 mb-3 text-sm">
                    <time datetime="<?= e(date('c', strtotime($k['geaendert_am']))) ?>" class="font-semibold text-fellDk">
                        <?= e(date('d.m.Y, H:i', strtotime($k['geaendert_am']))) ?> Uhr
                    </time>
                    <span class="text-fellDk/70"><?= e($k['username'] ?? 'unbekannt') ?></span>
                    <span class="text-xs font-bold px-2 py-0.5 rounded <?= $anlassFarben[$k['anlass']] ?? '' ?>">
                        <?= e(ContentRevision::ANLAESSE[$k['anlass']] ?? $k['anlass']) ?>
                    </span>
                </div>

                <div class="space-y-4">
                    <?php foreach ($vorgang['felder'] as $v): ?>
                        <div class="border-t border-sand pt-3">
                            <div class="flex flex-wrap items-center justify-between gap-2 mb-2">
                                <h2 class="text-sm font-bold text-fellDk"><?= e(ContentRevision::feldLabel($tabelle, $v['feld'])) ?></h2>
                                <?php if ($datensatz !== null && $v['anlass'] !== 'loeschung' && ContentRevision::istWiederherstellbar($tabelle, $v['feld'])): ?>
                                    <form method="post" onsubmit="return confirm('Feld „<?= e(ContentRevision::feldLabel($tabelle, $v['feld'])) ?>“ auf den Stand vor dieser Änderung zurücksetzen?');">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="revision_id" value="<?= (int) $v['id'] ?>">
                                        <button type="submit" class="text-sm font-semibold text-tanne hover:text-tanneDk border border-tanne/40 hover:border-tanne rounded-lg px-3 py-1.5 transition-colors">
                                            Stand davor wiederherstellen
                                        </button>
                                    </form>
                                <?php endif; ?>
                            </div>

                            <?php if ($v['anlass'] === 'loeschung'): ?>
                                <details>
                                    <summary class="text-sm text-tanne font-semibold cursor-pointer">Text vor dem Löschen anzeigen</summary>
                                    <div class="mt-2 text-sm leading-relaxed whitespace-pre-line text-fellDk bg-creme/60 rounded-lg p-3"><?= e($v['alter_wert']) ?></div>
                                </details>
                            <?php elseif (ist_langer_text($v['alter_wert'], $v['neuer_wert'])): ?>
                                <div class="wortdiff text-sm leading-relaxed whitespace-pre-line text-fellDk"><?= render_wortdiff($v['alter_wert'], $v['neuer_wert']) ?></div>
                                <details class="mt-2">
                                    <summary class="text-sm text-tanne font-semibold cursor-pointer">Vollständigen Text vorher anzeigen</summary>
                                    <div class="mt-2 text-sm leading-relaxed whitespace-pre-line text-fellDk bg-creme/60 rounded-lg p-3"><?= e($v['alter_wert'] ?? '(leer)') ?></div>
                                </details>
                            <?php else: ?>
                                <p class="text-sm text-fellDk">
                                    <span class="line-through decoration-1 text-fellDk/70"><?= $v['alter_wert'] !== null && $v['alter_wert'] !== '' ? e($v['alter_wert']) : '(leer)' ?></span>
                                    <span aria-hidden="true" class="mx-1">→</span><span class="sr-only">geändert zu</span>
                                    <span class="font-semibold"><?= $v['neuer_wert'] !== null && $v['neuer_wert'] !== '' ? e($v['neuer_wert']) : '(leer)' ?></span>
                                </p>
                            <?php endif; ?>
                        </div>
                    <?php endforeach; ?>
                </div>
            </li>
        <?php endforeach; ?>
    </ol>
<?php endif; ?>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
