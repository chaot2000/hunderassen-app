<?php
/**
 * admin_manage_tests.php
 *
 * Listen-/Löschseite für den Test-Katalog (Phase 1 des Test-/
 * Testdurchführungs-Frameworks). Anlegen/Bearbeiten der Aufgaben- und
 * Ergebnis-Struktur erfolgt NICHT inline wie bei Tags/Aktivitäten
 * (admin_manage_tags.php), sondern auf einer eigenen Seite
 * (test_form.php) — ein Test ist ein zweistufig verschachteltes
 * Formular (Aufgaben -> Ergebnisse), das für ein Inline-Muster zu groß
 * wäre (analog admin_add_breed.php, das aus demselben Grund eine
 * eigene Seite statt Inline-Bearbeitung ist).
 *
 * Allgemeine Tests (für alle Organisationen) stehen oben. Jede
 * Organisation kann sie für sich ausblenden. Bearbeiten, Löschen,
 * Freigeben eigener Tests und Zurückholen nur für den Super-Admin, die
 * Rechte prüft models/Test.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Test.php';

secure_session_start();
require_manage_tests();

$tenantId = current_tenant_id();
$userId   = (int) current_user()['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_require_valid();

    if (isset($_POST['delete_test_id'])) {
        $result = Test::delete((int) $_POST['delete_test_id'], $tenantId, $userId);
    } elseif (isset($_POST['ausblenden_test_id'])) {
        $result = Test::setzeAusgeblendet((int) $_POST['ausblenden_test_id'], $tenantId, true, $userId);
    } elseif (isset($_POST['einblenden_test_id'])) {
        $result = Test::setzeAusgeblendet((int) $_POST['einblenden_test_id'], $tenantId, false, $userId);
    } elseif (isset($_POST['freigeben_test_id'])) {
        $result = Test::freigeben((int) $_POST['freigeben_test_id'], $tenantId, $userId);
    } elseif (isset($_POST['zurueckholen_test_id'])) {
        $result = Test::zurueckholen((int) $_POST['zurueckholen_test_id'], $tenantId, $userId);
    } else {
        $result = ['success' => false, 'message' => 'Unbekannte Aktion.'];
    }

    if ($result['success']) {
        // PRG: Erfolgsmeldung über die Session, damit ein Neuladen nichts doppelt ausführt.
        $_SESSION['tests_meldung'] = $result['message'];
        header('Location: /admin_manage_tests.php');
        exit;
    }
    $errorMessage = $result['message'];
}

$meldung      = $_SESSION['tests_meldung'] ?? null;
$errorMessage = $errorMessage ?? null;
unset($_SESSION['tests_meldung']);

$istSuperAdmin       = Test::darfAllgemeineVerwalten();
$tests               = Test::findAll($tenantId);
$aufgabenCounts      = Test::getAufgabenCounts($tenantId);
$durchfuehrungCounts = Test::getDurchfuehrungCounts($tenantId);
$ausblendungCounts   = Test::getAusblendungCounts();
$zielgruppenLabels   = ['welpe' => 'Welpe', 'erwachsen' => 'Erwachsen', 'beide' => 'Beide'];

$pageTitle = 'Tests verwalten';
require __DIR__ . '/views/partials/header.php';
?>

<div class="flex items-center justify-between mb-6">
    <h1 class="text-2xl font-extrabold text-fellDk">🧪 Tests verwalten</h1>
    <a href="/test_form.php" class="bg-tanne hover:bg-tanneDk text-creme font-bold text-sm px-5 py-2.5 rounded-full transition-colors whitespace-nowrap">
        + Neuen Test anlegen
    </a>
</div>

<?php if ($meldung !== null): ?>
    <div class="bg-tanne/10 border border-tanne/40 text-tanneDk text-sm rounded-lg px-4 py-3 mb-6" role="status"><?= e($meldung) ?></div>
<?php endif; ?>
<?php if ($errorMessage !== null): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-6" role="alert"><?= e($errorMessage) ?></div>
<?php endif; ?>

<?php if (empty($tests)): ?>
    <div class="bg-white/80 rounded-2xl shadow border border-sand">
        <p class="text-center py-16 text-fellDk/50">Es wurden noch keine Tests angelegt.</p>
    </div>
<?php else: ?>
    <p class="text-sm text-fellDk/60 mb-3 max-w-[70ch]">
        Tests mit dem Etikett „Allgemein“ stehen allen Organisationen zur Verfügung. Ausgeblendete allgemeine Tests
        fehlen bei neuen Durchführungen, bestehende Durchführungen bleiben erhalten. Die Zahl der Durchführungen
        zählt nur Hunde deiner Organisation.
    </p>
    <div class="bg-white/80 rounded-2xl shadow border border-sand overflow-hidden">
        <div class="overflow-x-auto">
            <table class="w-full text-sm">
                <thead>
                    <tr class="bg-sand/30 text-left text-xs font-semibold uppercase tracking-wide text-fellDk/60">
                        <th class="px-4 py-3">Name</th>
                        <th class="px-4 py-3">Beschreibung</th>
                        <th class="px-4 py-3">Zielgruppe</th>
                        <th class="px-4 py-3">Aufgaben</th>
                        <th class="px-4 py-3">Durchführungen</th>
                        <th class="px-4 py-3 text-right">Aktionen</th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-sand">
                    <?php foreach ($tests as $test):
                        $testId = (int) $test['id'];
                        $aufgabenCount = $aufgabenCounts[$testId] ?? 0;
                        $durchfuehrungCount = $durchfuehrungCounts[$testId] ?? 0;
                        $allgemein = $test['allgemein'];
                        $ausgeblendet = $test['ausgeblendet'];
                        $darfBearbeiten = !$allgemein || $istSuperAdmin;
                    ?>
                        <tr class="hover:bg-creme/60 transition-colors<?= $ausgeblendet ? ' text-fellDk/50' : '' ?>">
                            <td class="px-4 py-3 font-semibold">
                                <?= e($test['name']) ?>
                                <?php if ($allgemein): ?>
                                    <span class="ml-1 inline-block text-xs font-bold bg-pfote/15 text-fell rounded-full px-2 py-0.5 align-middle">Allgemein</span>
                                <?php endif; ?>
                                <?php if ($ausgeblendet): ?>
                                    <span class="ml-1 inline-block text-xs font-semibold border border-sand rounded-full px-2 py-0.5 align-middle">ausgeblendet</span>
                                <?php endif; ?>
                                <?php if ($allgemein && $istSuperAdmin && !empty($ausblendungCounts[$testId])): ?>
                                    <span class="block text-xs font-normal text-fellDk/50">In <?= (int) $ausblendungCounts[$testId] ?> Organisation(en) ausgeblendet</span>
                                <?php endif; ?>
                            </td>
                            <td class="px-4 py-3 text-fellDk/70"><?= !empty($test['beschreibung']) ? e($test['beschreibung']) : '—' ?></td>
                            <td class="px-4 py-3 text-fellDk/70"><?= e($zielgruppenLabels[$test['zielgruppe']] ?? $test['zielgruppe']) ?></td>
                            <td class="px-4 py-3"><?= $aufgabenCount ?></td>
                            <td class="px-4 py-3"><?= $durchfuehrungCount ?></td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <?php if ($allgemein): ?>
                                    <form method="post" action="/admin_manage_tests.php" class="inline mr-3">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="<?= $ausgeblendet ? 'einblenden_test_id' : 'ausblenden_test_id' ?>" value="<?= $testId ?>">
                                        <button type="submit" class="text-tanne hover:text-tanneDk font-semibold"><?= $ausgeblendet ? 'Einblenden' : 'Ausblenden' ?></button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($darfBearbeiten): ?>
                                    <a href="/test_form.php?id=<?= $testId ?>" class="text-tanne hover:text-tanneDk font-semibold mr-3">Bearbeiten</a>
                                <?php endif; ?>
                                <?php if ($istSuperAdmin && !$allgemein): ?>
                                    <form method="post" action="/admin_manage_tests.php" class="inline mr-3"
                                          onsubmit="return confirm(<?= e(json_encode('Test „' . $test['name'] . '“ für alle Organisationen freigeben? Name, Beschreibung, Aufgaben und Ergebnisoptionen sind danach überall sichtbar. Durchführungen und Ergebnisse bleiben bei deiner Organisation.', JSON_UNESCAPED_UNICODE)) ?>);">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="freigeben_test_id" value="<?= $testId ?>">
                                        <button type="submit" class="text-tanne hover:text-tanneDk font-semibold">Für alle freigeben</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($istSuperAdmin && $allgemein): ?>
                                    <form method="post" action="/admin_manage_tests.php" class="inline mr-3"
                                          onsubmit="return confirm(<?= e(json_encode('Test „' . $test['name'] . '“ zurück in deine Organisation holen? Er verschwindet dann bei allen anderen Organisationen.', JSON_UNESCAPED_UNICODE)) ?>);">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="zurueckholen_test_id" value="<?= $testId ?>">
                                        <button type="submit" class="text-tanne hover:text-tanneDk font-semibold">Zurückholen</button>
                                    </form>
                                <?php endif; ?>
                                <?php if ($darfBearbeiten): ?>
                                    <form method="post" action="/admin_manage_tests.php" class="inline"
                                          onsubmit="return confirm(<?= e(json_encode('Test „' . $test['name'] . '“ wirklich löschen?' . ($allgemein ? ' Er verschwindet bei allen Organisationen. Ist er irgendwo schon durchgeführt worden, wird das Löschen abgelehnt.' : ($durchfuehrungCount > 0 ? ' Achtung: ' . $durchfuehrungCount . ' Durchführung(en) verweisen darauf, das Löschen wird daher abgelehnt.' : ' Alle zugehörigen Aufgaben und Ergebnisse werden mitgelöscht.')), JSON_UNESCAPED_UNICODE)) ?>);">
                                        <?= csrf_field() ?>
                                        <input type="hidden" name="delete_test_id" value="<?= $testId ?>">
                                        <button type="submit" class="text-red-600 hover:text-red-800 font-semibold">Löschen<?= !$allgemein && $durchfuehrungCount > 0 ? ' (' . $durchfuehrungCount . ')' : '' ?></button>
                                    </form>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
