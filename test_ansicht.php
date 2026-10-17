<?php
/**
 * test_ansicht.php
 *
 * Leseansicht eines Tests: alle Aufgaben mit Anleitung und Ergebnisoptionen,
 * nach Abschnitten gegliedert. Zeigt eigene und allgemeine Tests, ändert
 * nichts. Bearbeitet wird weiter in test_form.php.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Test.php';

secure_session_start();
require_manage_tests();

$test = Test::findById((int) ($_GET['id'] ?? 0), current_tenant_id());
if ($test === null) {
    http_response_code(404);
    die(e(t('tests.angefordert')));
}

$darfBearbeiten = !$test['allgemein'] || Test::darfAllgemeineVerwalten();
$zielgruppenLabels = ['welpe' => 'Welpe', 'erwachsen' => 'Erwachsen', 'beide' => 'Beide'];
$kategorieLabels = ['bestanden' => t('status.bestanden'), 'neutral' => t('status.neutral'), 'nicht_bestanden' => t('status.nicht_bestanden')];
$kategorieFarben = ['bestanden' => 'bg-tanne/10 text-tanneDk', 'neutral' => 'bg-pfote/20 text-fellDk/70', 'nicht_bestanden' => 'bg-red-50 text-red-700'];

// Abschnitte in der Reihenfolge ihres ersten Auftretens, für die Sprungmarken.
$abschnitte = [];
foreach ($test['aufgaben'] as $a) {
    $name = trim((string) ($a['abschnitt'] ?? ''));
    if ($name !== '' && !in_array($name, $abschnitte, true)) {
        $abschnitte[] = $name;
    }
}

$pageTitle = $test['name'];
require __DIR__ . '/views/partials/header.php';
?>

<a href="/admin_manage_tests.php" class="inline-flex items-center gap-1.5 text-sm text-fellDk/60 hover:text-fellDk mb-4">← Zu den Tests</a>

<div class="bg-white/80 rounded-2xl shadow border border-sand p-4 sm:p-6 max-w-3xl">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div class="min-w-0">
            <h1 class="text-2xl font-extrabold text-fellDk break-words"><?= e($test['name']) ?></h1>
            <p class="text-sm text-fellDk/60 mt-1">
                <?= e($zielgruppenLabels[$test['zielgruppe']] ?? $test['zielgruppe']) ?> · <?= count($test['aufgaben']) ?> Aufgaben
                <?php if ($test['allgemein']): ?>
                    <span class="ml-1 inline-block text-xs font-bold bg-pfote/15 text-fell rounded-full px-2 py-0.5 align-middle">Allgemein</span>
                <?php endif; ?>
            </p>
        </div>
        <?php if ($darfBearbeiten): ?>
            <a href="/test_form.php?id=<?= (int) $test['id'] ?>" class="bg-pfote/10 text-fell font-semibold text-sm px-4 py-2 rounded-full hover:bg-pfote/20 transition-colors whitespace-nowrap">Bearbeiten</a>
        <?php endif; ?>
    </div>

    <?php if (!empty($test['beschreibung'])): ?>
        <p class="text-sm text-fellDk/70 mt-4 whitespace-pre-line"><?= e($test['beschreibung']) ?></p>
    <?php endif; ?>

    <?php if (count($abschnitte) > 1): ?>
        <nav class="mt-4 flex flex-wrap gap-2" aria-label="Abschnitte">
            <?php foreach ($abschnitte as $i => $name): ?>
                <a href="#abschnitt-<?= $i + 1 ?>" class="text-xs font-semibold border border-sand rounded-full px-3 py-1 hover:bg-sand/30"><?= e($name) ?></a>
            <?php endforeach; ?>
        </nav>
    <?php endif; ?>

    <div class="mt-6 space-y-3">
        <?php if (empty($test['aufgaben'])): ?>
            <p class="text-sm text-fellDk/50">Dieser Test hat noch keine Aufgaben.</p>
        <?php endif; ?>
        <?php $letzterAbschnitt = null; ?>
        <?php foreach ($test['aufgaben'] as $nr => $aufgabe):
            $abschnitt = trim((string) ($aufgabe['abschnitt'] ?? ''));
        ?>
            <?php if ($abschnitt !== '' && $abschnitt !== $letzterAbschnitt): ?>
                <h2 id="abschnitt-<?= array_search($abschnitt, $abschnitte, true) + 1 ?>" class="text-lg font-bold text-fellDk pt-4 border-b border-sand pb-1 scroll-mt-4"><?= e($abschnitt) ?></h2>
            <?php endif; ?>
            <?php $letzterAbschnitt = $abschnitt; ?>
            <div class="border border-sand rounded-lg p-4">
                <p class="font-semibold text-sm"><span class="text-fellDk/50"><?= $nr + 1 ?>.</span> <?= e($aufgabe['titel']) ?></p>
                <?php if (!empty($aufgabe['beschreibung'])): ?>
                    <p class="text-sm text-fellDk/70 mt-1 whitespace-pre-line"><?= e($aufgabe['beschreibung']) ?></p>
                <?php endif; ?>
                <?php if (!empty($aufgabe['ergebnisse'])): ?>
                    <ul class="mt-3 space-y-1.5">
                        <?php foreach ($aufgabe['ergebnisse'] as $ergebnis): ?>
                            <li class="flex items-start gap-2 text-sm">
                                <span class="shrink-0 text-xs font-semibold px-2 py-0.5 rounded-full <?= $kategorieFarben[$ergebnis['kategorie']] ?? 'bg-sand/50 text-fellDk/70' ?>"><?= e($kategorieLabels[$ergebnis['kategorie']] ?? $ergebnis['kategorie']) ?></span>
                                <span class="text-fellDk/80"><?= e($ergebnis['bezeichnung']) ?></span>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    </div>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
