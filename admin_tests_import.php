<?php
/**
 * admin_tests_import.php
 *
 * Importiert Tests aus einer JSON-Datei, die tools/excel_tests_umwandeln.py
 * aus den Excel-Eignungstests erzeugt. Ablauf: hochladen → Vorschau je
 * Test → übernehmen. Angelegt wird in der eigenen Organisation, schon
 * vorhandene Namen werden übersprungen (models/TestImport.php).
 *
 * Die Datei liegt bis zur Übernahme außerhalb des Webverzeichnisses im
 * Temp-Ordner und wird danach gelöscht, wie beim Inhalte-Import.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/TestImport.php';

secure_session_start();
require_manage_tests();
set_time_limit(180);

$tenantId = current_tenant_id();
$ablage = $_SESSION['tests_import'] ?? null;
$ablageGueltig = is_array($ablage)
    && (int) ($ablage['tenant_id'] ?? 0) === $tenantId
    && is_file($ablage['pfad'] ?? '')
    && hash_file('sha256', $ablage['pfad']) === ($ablage['sha'] ?? '');

function tests_import_verwerfen(): void
{
    $pfad = $_SESSION['tests_import']['pfad'] ?? null;
    if (is_string($pfad) && is_file($pfad) && str_starts_with(basename($pfad), 'tests_import_')) {
        unlink($pfad);
    }
    unset($_SESSION['tests_import']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();
    $aktion = $_POST['aktion'] ?? '';
    if ($aktion === 'hochladen') {
        tests_import_verwerfen();
        $datei = $_FILES['datei'] ?? null;
        $inhalt = is_array($datei) && ($datei['error'] ?? 1) === UPLOAD_ERR_OK && $datei['size'] <= TestImport::MAX_BYTES
            ? (string) file_get_contents($datei['tmp_name']) : '';
        $gelesen = $inhalt !== '' ? TestImport::lesen($inhalt) : ['daten' => null, 'fehler' => 'Keine Datei angekommen oder größer als 5 MB.'];
        if ($gelesen['daten'] === null) {
            $_SESSION['tests_import_meldung'] = [false, $gelesen['fehler']];
        } else {
            $pfad = rtrim(sys_get_temp_dir(), '/\\') . '/tests_import_' . bin2hex(random_bytes(16)) . '.json';
            file_put_contents($pfad, $inhalt);
            $_SESSION['tests_import'] = [
                'pfad'      => $pfad,
                'sha'       => hash('sha256', $inhalt),
                'name'      => basename((string) $datei['name']),
                'tenant_id' => $tenantId,
            ];
        }
    } elseif ($aktion === 'anwenden' && $ablageGueltig) {
        $daten = TestImport::lesen((string) file_get_contents($ablage['pfad']))['daten'];
        try {
            $e = TestImport::anwenden($daten, $tenantId, (int) current_user()['id']);
            $text = $e['angelegt'] . ' ' . ($e['angelegt'] === 1 ? 'Test angelegt' : 'Tests angelegt')
                . ', ' . $e['uebersprungen'] . ' übersprungen.';
            if ($e['fehler']) {
                $text .= ' Nicht angelegt: ' . implode(' | ', $e['fehler']);
            }
            $_SESSION['tests_import_meldung'] = [!$e['fehler'], $text];
            tests_import_verwerfen();
        } catch (Throwable $ex) {
            error_log('Tests importieren: ' . $ex->getMessage());
            $_SESSION['tests_import_meldung'] = [false, 'Beim Übernehmen ist ein Fehler aufgetreten. Bereits angelegte Tests stehen in der Testliste.'];
        }
    } elseif ($aktion === 'verwerfen') {
        tests_import_verwerfen();
    }
    header('Location: /admin_tests_import.php');
    exit;
}

[$meldungOk, $meldung] = $_SESSION['tests_import_meldung'] ?? [null, null];
unset($_SESSION['tests_import_meldung']);

$vorschau = null;
$warnungen = [];
if ($ablageGueltig) {
    $daten = TestImport::lesen((string) file_get_contents($ablage['pfad']))['daten'];
    if ($daten) {
        $vorschau = TestImport::pruefen($daten, $tenantId);
        $warnungen = $daten['warnungen'];
    }
} elseif ($ablage !== null) {
    tests_import_verwerfen();
}
$neu = $vorschau ? count(array_filter($vorschau, fn($z) => $z['status'] === 'neu')) : 0;

const TI_STATUS_NAME  = ['neu' => 'wird angelegt', 'schon' => 'übersprungen', 'fehler' => 'Fehler'];
const TI_STATUS_FARBE = ['neu' => 'bg-tanne/10 text-tanneDk', 'schon' => 'bg-sand/40 text-fellDk', 'fehler' => 'bg-red-50 text-red-700'];
const TI_ZIELGRUPPE   = ['welpe' => 'Welpe', 'erwachsen' => 'Erwachsen', 'beide' => 'Beide'];

$pageTitle = 'Tests importieren';
require __DIR__ . '/views/partials/header.php';
?>

<a href="/admin_manage_tests.php" class="inline-flex items-center gap-1.5 text-sm text-fellDk/60 hover:text-fellDk mb-4">← Zu den Tests</a>

<div class="max-w-5xl">
    <h1 class="text-2xl font-extrabold text-fellDk mb-2">📥 Tests importieren</h1>
    <p class="text-fellDk mb-6 max-w-[70ch]">
        Übernimmt Tests aus einer JSON-Datei, die das Skript <code>tools/excel_tests_umwandeln.py</code> aus den
        Excel-Eignungstests erzeugt. Die Tests werden in deiner Organisation angelegt. Gibt es einen Namen schon,
        wird der Test übersprungen.
    </p>

    <?php if ($meldung !== null): ?>
        <p class="<?= $meldungOk ? 'bg-tanne/10 border-tanne/40 text-tanneDk' : 'bg-red-50 border-red-200 text-red-700' ?> border rounded-lg px-4 py-3 mb-6" role="<?= $meldungOk ? 'status' : 'alert' ?>"><?= e($meldung) ?></p>
    <?php endif; ?>

    <?php if ($vorschau === null): ?>
        <form method="post" action="/admin_tests_import.php" enctype="multipart/form-data"
              class="bg-white/80 rounded-2xl shadow border border-sand p-6 flex flex-wrap items-end gap-4">
            <?= csrf_field() ?>
            <input type="hidden" name="aktion" value="hochladen">
            <div>
                <label for="datei" class="block text-sm font-semibold mb-1">Testdatei (.json)</label>
                <input type="file" id="datei" name="datei" accept=".json,application/json" required class="text-sm">
            </div>
            <button type="submit" class="bg-tanne hover:bg-tanneDk text-creme font-bold text-sm px-5 py-2.5 rounded-full transition-colors">Hochladen und prüfen</button>
        </form>
    <?php else: ?>
        <section class="bg-white/80 rounded-2xl shadow border border-sand p-6 mb-6">
            <h2 class="text-lg font-bold text-fellDk mb-1">Vorschau: <?= e($ablage['name']) ?></h2>
            <p class="text-sm text-fellDk/60 mb-4"><?= $neu ?> von <?= count($vorschau) ?> Tests werden angelegt.</p>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="bg-sand/30 text-left text-xs font-semibold uppercase tracking-wide text-fellDk/60">
                            <th class="px-3 py-2">Name</th>
                            <th class="px-3 py-2">Zielgruppe</th>
                            <th class="px-3 py-2">Aufgaben</th>
                            <th class="px-3 py-2">Abschnitte</th>
                            <th class="px-3 py-2">Optionen</th>
                            <th class="px-3 py-2">Status</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-sand">
                        <?php foreach ($vorschau as $z): ?>
                            <tr>
                                <td class="px-3 py-2">
                                    <span class="font-semibold"><?= e($z['name']) ?></span>
                                    <?php if ($z['quelle'] !== ''): ?><span class="block text-xs text-fellDk/50"><?= e($z['quelle']) ?></span><?php endif; ?>
                                </td>
                                <td class="px-3 py-2"><?= e(TI_ZIELGRUPPE[$z['zielgruppe']] ?? $z['zielgruppe']) ?></td>
                                <td class="px-3 py-2"><?= (int) $z['aufgaben'] ?></td>
                                <td class="px-3 py-2"><?= (int) $z['abschnitte'] ?></td>
                                <td class="px-3 py-2"><?= (int) $z['optionen'] ?></td>
                                <td class="px-3 py-2">
                                    <span class="inline-block text-xs font-semibold rounded-full px-2 py-0.5 <?= TI_STATUS_FARBE[$z['status']] ?>"><?= e(TI_STATUS_NAME[$z['status']]) ?></span>
                                    <?php if ($z['status'] !== 'neu'): ?><span class="block text-xs text-fellDk/60"><?= e($z['meldung']) ?></span><?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($warnungen): ?>
                <details class="mt-4">
                    <summary class="text-sm font-semibold text-fellDk cursor-pointer">Hinweise des Umwandlers (<?= count($warnungen) ?>)</summary>
                    <ul class="list-disc pl-6 mt-2 text-sm text-fellDk/70 space-y-1">
                        <?php foreach ($warnungen as $w): ?><li><?= e($w) ?></li><?php endforeach; ?>
                    </ul>
                </details>
            <?php endif; ?>

            <div class="flex flex-wrap gap-3 mt-6">
                <?php if ($neu > 0): ?>
                    <form method="post" action="/admin_tests_import.php">
                        <?= csrf_field() ?>
                        <input type="hidden" name="aktion" value="anwenden">
                        <button type="submit" class="bg-tanne hover:bg-tanneDk text-creme font-bold text-sm px-5 py-2.5 rounded-full transition-colors"><?= $neu ?> Tests anlegen</button>
                    </form>
                <?php endif; ?>
                <form method="post" action="/admin_tests_import.php">
                    <?= csrf_field() ?>
                    <input type="hidden" name="aktion" value="verwerfen">
                    <button type="submit" class="text-fellDk/70 hover:text-fellDk font-semibold text-sm px-5 py-2.5">Verwerfen</button>
                </form>
            </div>
        </section>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
