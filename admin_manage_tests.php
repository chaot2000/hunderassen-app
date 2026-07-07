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
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Test.php';

secure_session_start();
require_admin();

$errorMessage = null;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_test_id'])) {
    csrf_require_valid();
    $result = Test::delete((int) $_POST['delete_test_id']);
    if ($result['success']) {
        header('Location: /admin_manage_tests.php');
        exit;
    }
    $errorMessage = $result['message'];
}

$tests               = Test::findAll();
$aufgabenCounts      = Test::getAufgabenCounts();
$durchfuehrungCounts = Test::getDurchfuehrungCounts();
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

<?php if ($errorMessage !== null): ?>
    <div class="bg-red-50 border border-red-200 text-red-700 text-sm rounded-lg px-4 py-3 mb-6" role="alert"><?= e($errorMessage) ?></div>
<?php endif; ?>

<?php if (empty($tests)): ?>
    <div class="bg-white/80 rounded-2xl shadow border border-sand">
        <p class="text-center py-16 text-fellDk/50">Es wurden noch keine Tests angelegt.</p>
    </div>
<?php else: ?>
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
                    ?>
                        <tr class="hover:bg-creme/60 transition-colors">
                            <td class="px-4 py-3 font-semibold"><?= e($test['name']) ?></td>
                            <td class="px-4 py-3 text-fellDk/70"><?= !empty($test['beschreibung']) ? e($test['beschreibung']) : '—' ?></td>
                            <td class="px-4 py-3 text-fellDk/70"><?= e($zielgruppenLabels[$test['zielgruppe']] ?? $test['zielgruppe']) ?></td>
                            <td class="px-4 py-3"><?= $aufgabenCount ?></td>
                            <td class="px-4 py-3"><?= $durchfuehrungCount ?></td>
                            <td class="px-4 py-3 text-right whitespace-nowrap">
                                <a href="/test_form.php?id=<?= $testId ?>" class="text-tanne hover:text-tanneDk font-semibold mr-3">Bearbeiten</a>
                                <form method="post" action="/admin_manage_tests.php" class="inline"
                                      onsubmit="return confirm('Test „<?= e($test['name']) ?>“ wirklich löschen?<?= $durchfuehrungCount > 0 ? ' Achtung: ' . $durchfuehrungCount . ' Durchführung(en) verweisen darauf, das Löschen wird daher abgelehnt.' : ' Alle zugehörigen Aufgaben und Ergebnisse werden mitgelöscht.' ?>');">
                                    <?= csrf_field() ?>
                                    <input type="hidden" name="delete_test_id" value="<?= $testId ?>">
                                    <button type="submit" class="text-red-600 hover:text-red-800 font-semibold">Löschen<?= $durchfuehrungCount > 0 ? ' (' . $durchfuehrungCount . ')' : '' ?></button>
                                </form>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
