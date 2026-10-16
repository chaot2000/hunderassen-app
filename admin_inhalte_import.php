<?php
/**
 * admin_inhalte_import.php
 *
 * Importiert geprüfte Inhalte aus dem privaten Inhalte-Repo (PLAN.md
 * Abschnitt 11). Ablauf: Importdatei hochladen → Vorschau mit Status je
 * Änderung → übernehmen. Geschrieben wird nur, was zum Live-Stand passt
 * (models/InhalteImport.php). Die Datei liegt bis dahin außerhalb des
 * Webverzeichnisses im Temp-Ordner und wird danach gelöscht.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/InhalteImport.php';

secure_session_start();
// Schreibt in den geteilten Katalog aller Mandanten
require_super_admin();
set_time_limit(180);

$ablage = $_SESSION['inhalte_import'] ?? null;
$ablageGueltig = is_array($ablage) && is_file($ablage['pfad'] ?? '') && hash_file('sha256', $ablage['pfad']) === ($ablage['sha'] ?? '');

function import_verwerfen(): void
{
    $pfad = $_SESSION['inhalte_import']['pfad'] ?? null;
    if (is_string($pfad) && is_file($pfad) && str_starts_with(basename($pfad), 'inhalte_import_')) {
        unlink($pfad);
    }
    unset($_SESSION['inhalte_import']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $aktion = $_POST['aktion'] ?? '';
    if ($aktion === 'hochladen') {
        import_verwerfen();
        $datei = $_FILES['datei'] ?? null;
        $inhalt = is_array($datei) && ($datei['error'] ?? 1) === UPLOAD_ERR_OK && $datei['size'] <= InhalteImport::MAX_BYTES
            ? (string) file_get_contents($datei['tmp_name']) : '';
        $gelesen = $inhalt !== '' ? InhalteImport::lesen($inhalt) : ['daten' => null, 'fehler' => 'Keine Datei angekommen oder größer als 10 MB.'];
        if ($gelesen['daten'] === null) {
            $_SESSION['inhalte_meldung'] = [false, $gelesen['fehler']];
        } else {
            $pfad = rtrim(sys_get_temp_dir(), '/\\') . '/inhalte_import_' . bin2hex(random_bytes(16)) . '.json';
            file_put_contents($pfad, $inhalt);
            $_SESSION['inhalte_import'] = ['pfad' => $pfad, 'sha' => hash('sha256', $inhalt), 'name' => basename((string) $datei['name'])];
        }
    } elseif ($aktion === 'anwenden' && $ablageGueltig) {
        $daten = InhalteImport::lesen((string) file_get_contents($ablage['pfad']))['daten'];
        try {
            $e = InhalteImport::anwenden($daten, (int) current_user()['id']);
            $zahl = fn(int $n, string $eins, string $mehr) => $n . ' ' . ($n === 1 ? $eins : $mehr);
            $_SESSION['inhalte_meldung'] = [true, 'Übernommen: ' . $zahl($e['felder'], 'Änderung', 'Änderungen') . ' bei ' . $zahl($e['rassen'], 'Rasse', 'Rassen')
                . ', ' . $zahl($e['werte'], 'Wert', 'Werte') . ' neu oder umbenannt.'];
            import_verwerfen();
        } catch (Throwable $ex) {
            error_log('Inhalte importieren: ' . $ex->getMessage());
            $_SESSION['inhalte_meldung'] = [false, 'Beim Übernehmen ist ein Fehler aufgetreten. Es wurde nichts geändert.'];
        }
    } elseif ($aktion === 'verwerfen') {
        import_verwerfen();
    }
    header('Location: /admin_inhalte_import.php');
    exit;
}

[$meldungOk, $meldung] = $_SESSION['inhalte_meldung'] ?? [null, null];
unset($_SESSION['inhalte_meldung']);

$pruefung = null;
if ($ablageGueltig) {
    $daten = InhalteImport::lesen((string) file_get_contents($ablage['pfad']))['daten'];
    $pruefung = $daten ? InhalteImport::pruefen($daten) : null;
} elseif ($ablage !== null) {
    import_verwerfen();
}
$definitionen = RassenDatenSchreiben::nachSchluessel();

/** Anzeige eines alten/neuen/Live-Werts */
function import_wert(array $a, $wert, array $definitionen): string
{
    if ($wert === null || $wert === '') {
        return '–';
    }
    if ($a['art'] === 'merkmal') {
        $m = $definitionen[$a['feld']] ?? null;
        return $m ? InhaltsDatei::wertText($m, $wert) : json_encode($wert, JSON_UNESCAPED_UNICODE);
    }
    return (string) $wert;
}

const STATUS_NAME = ['ok' => 'wird übernommen', 'schon' => 'schon so', 'konflikt' => 'Konflikt', 'fehler' => 'Fehler'];
const STATUS_FARBE = ['ok' => 'bg-tanne/10 text-tanneDk', 'schon' => 'bg-sand/40 text-fellDk', 'konflikt' => 'bg-pfote/20 text-fellDk', 'fehler' => 'bg-red-50 text-red-700'];

$pageTitle = 'Inhalte importieren';
require __DIR__ . '/views/partials/header.php';
?>

<div class="max-w-5xl">
    <h1 class="text-2xl font-extrabold text-fellDk mb-2">Inhalte importieren</h1>
    <p class="text-fellDk mb-6 max-w-[70ch]">
        Übernimmt geprüfte Inhalte aus dem Inhalte-Repo (Importdatei aus <code class="font-mono text-sm">tools/inhalte_importdatei.php</code>).
        Geschrieben wird nur, wo der Live-Wert noch dem Stand beim Export entspricht. Wurde etwas inzwischen in der App geändert,
        erscheint es als Konflikt und bleibt unverändert. Jede Übernahme steht in den Versionen der Rasse.
    </p>

    <?php if ($meldung !== null): ?>
        <p class="<?= $meldungOk ? 'bg-tanne/10 border-tanne/40 text-tanneDk' : 'bg-red-50 border-red-200 text-red-700' ?> border rounded-lg px-4 py-3 mb-6" role="<?= $meldungOk ? 'status' : 'alert' ?>"><?= e($meldung) ?></p>
    <?php endif; ?>

    <?php if ($pruefung === null): ?>
        <form method="post" action="/admin_inhalte_import.php" enctype="multipart/form-data" class="bg-white/80 rounded-2xl shadow border border-sand p-6 flex flex-wrap items-end gap-4">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="hochladen">
            <label class="text-sm font-semibold">Importdatei (.json)
                <input type="file" name="datei" accept=".json,application/json" required class="block mt-1 text-sm">
            </label>
            <button type="submit" class="min-h-[44px] bg-tanne hover:bg-tanneDk text-creme font-bold px-5 rounded-lg">Hochladen und prüfen</button>
        </form>
    <?php else:
        $z = $pruefung['zahlen'];
        $nachStatus = [];
        foreach ($pruefung['aenderungen'] as $a) {
            $nachStatus[$a['status']][] = $a;
        } ?>
        <section class="bg-white/80 rounded-2xl shadow border border-sand p-6 mb-6">
            <h2 class="text-lg font-bold text-fellDk mb-1">Vorschau: <?= e($ablage['name']) ?></h2>
            <dl class="grid grid-cols-2 sm:grid-cols-4 gap-4 my-4">
                <?php foreach (STATUS_NAME as $s => $label): ?>
                    <div class="rounded-xl border border-sand p-3 <?= STATUS_FARBE[$s] ?>">
                        <dt class="text-sm"><?= e($label) ?></dt>
                        <dd class="text-2xl font-extrabold tabular-nums"><?= $z[$s] ?></dd>
                    </div>
                <?php endforeach; ?>
            </dl>
            <div class="flex flex-wrap gap-3">
                <form method="post" action="/admin_inhalte_import.php"
                    onsubmit="return confirm(<?= e(json_encode($z['ok'] . ' Änderungen übernehmen?', JSON_UNESCAPED_UNICODE)) ?>);">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aktion" value="anwenden">
                    <button type="submit" class="min-h-[44px] bg-tanne hover:bg-tanneDk text-creme font-bold px-5 rounded-lg disabled:opacity-60" <?= $z['ok'] === 0 ? 'disabled' : '' ?>>
                        <?= $z['ok'] ?> Änderungen übernehmen
                    </button>
                </form>
                <form method="post" action="/admin_inhalte_import.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aktion" value="verwerfen">
                    <button type="submit" class="min-h-[44px] border border-sand hover:bg-creme font-bold px-5 rounded-lg">Verwerfen</button>
                </form>
            </div>
        </section>

        <?php if ($pruefung['werte']): ?>
            <details class="bg-white/80 rounded-2xl border border-sand p-4 mb-4" <?= array_filter($pruefung['werte'], fn($w) => $w['status'] !== 'ok') ? 'open' : '' ?>>
                <summary class="font-bold text-fellDk cursor-pointer">Werte (<?= count($pruefung['werte']) ?>)</summary>
                <ul class="mt-3 space-y-1 text-sm">
                    <?php foreach ($pruefung['werte'] as $w): ?>
                        <li><span class="text-xs rounded px-2 py-0.5 <?= STATUS_FARBE[$w['status']] ?>"><?= e(STATUS_NAME[$w['status']]) ?></span>
                            <?= e(($definitionen[$w['merkmal']]['name_de'] ?? $w['merkmal']) . ': ' . ($w['neu']['name_de'] ?? '?') . ' | ' . ($w['neu']['name_en'] ?? '')) ?>
                            <?= $w['alt'] === null ? '<span class="text-fellDk/70">(neu)</span>' : '' ?></li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endif; ?>

        <?php foreach (['fehler', 'konflikt', 'ok', 'schon'] as $s):
            if (empty($nachStatus[$s])) {
                continue;
            } ?>
            <details class="bg-white/80 rounded-2xl border border-sand p-4 mb-4" <?= in_array($s, ['fehler', 'konflikt'], true) ? 'open' : '' ?>>
                <summary class="font-bold text-fellDk cursor-pointer"><?= e(STATUS_NAME[$s]) ?> (<?= count($nachStatus[$s]) ?>)</summary>
                <ul class="mt-3 divide-y divide-sand">
                    <?php foreach ($nachStatus[$s] as $a): ?>
                        <li class="py-3 text-sm">
                            <p><a href="/breed_detail.php?id=<?= (int) $a['breed_id'] ?>" class="font-semibold hover:underline" target="_blank" rel="noopener"><?= e($a['rasse']) ?></a> · <?= e($a['label']) ?></p>
                            <div class="mt-1 grid grid-cols-1 md:grid-cols-<?= $s === 'konflikt' ? '3' : '2' ?> gap-3">
                                <?php foreach (['vorher' => $a['alt'], 'neu' => $a['neu']] + ($s === 'konflikt' ? ['live jetzt' => $a['live']] : []) as $titel => $wert):
                                    $text = import_wert($a, $wert, $definitionen); ?>
                                    <div class="rounded-lg bg-creme/60 p-2">
                                        <p class="text-xs font-semibold uppercase tracking-wide text-fellDk/70"><?= e($titel) ?></p>
                                        <?php if (mb_strlen($text) > 240): ?>
                                            <details><summary class="cursor-pointer"><?= e(mb_substr($text, 0, 240)) ?> …</summary><p class="whitespace-pre-line mt-1"><?= e($text) ?></p></details>
                                        <?php else: ?>
                                            <p class="whitespace-pre-line"><?= e($text) ?></p>
                                        <?php endif; ?>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </li>
                    <?php endforeach; ?>
                </ul>
            </details>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
