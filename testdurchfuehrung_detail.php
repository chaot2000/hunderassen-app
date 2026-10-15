<?php
/**
 * testdurchfuehrung_detail.php
 *
 * Detailansicht einer einzelnen Testdurchführung: Test-Name, Datum,
 * Gesamtstatus, Gesamtnotiz, sowie der per-Aufgabe-Breakdown (welches
 * Ergebnis wurde bei welcher Aufgabe beobachtet, inkl. Kategorie-Badge
 * und Einzelnotiz). Struktur analog dog_detail.php: Berechtigung läuft
 * über den zugehörigen Hund (Dog::findByIdForUser()) — nur Ersteller
 * des Hundes oder Admin dürfen die Durchführung überhaupt SEHEN.
 */

declare(strict_types=1);

require_once __DIR__ . '/config/security.php';
require_once __DIR__ . '/config/auth.php';
require_once __DIR__ . '/models/Dog.php';
require_once __DIR__ . '/models/TestDurchfuehrung.php';

secure_session_start();
require_login();

$currentUserId = (int) current_user()['id'];
$isAdmin       = is_admin();
$tenantId      = current_tenant_id();

$durchfuehrungId = isset($_GET['id']) ? (int) $_GET['id'] : 0;
$ownerDogId      = $durchfuehrungId ? TestDurchfuehrung::findDogIdById($durchfuehrungId) : null;
$dog             = $ownerDogId !== null ? Dog::findByIdForUser($ownerDogId, $currentUserId, $isAdmin, $tenantId) : null;

if ($dog === null) {
    http_response_code(404);
    $pageTitle = t('allg.nicht_gefunden');
    require __DIR__ . '/views/partials/header.php';
    echo '<div class="text-center py-16 text-fellDk/50"><p>' . e(t('td.nicht_gefunden')) . '</p></div>';
    require __DIR__ . '/views/partials/footer.php';
    exit;
}

$durchfuehrung = TestDurchfuehrung::findByIdForDog($durchfuehrungId, (int) $dog['id']);
if ($durchfuehrung === null) {
    http_response_code(404);
    $pageTitle = t('allg.nicht_gefunden');
    require __DIR__ . '/views/partials/header.php';
    echo '<div class="text-center py-16 text-fellDk/50"><p>' . e(t('td.nicht_gefunden')) . '</p></div>';
    require __DIR__ . '/views/partials/footer.php';
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_durchfuehrung_id'])) {
    csrf_require_valid();
    // Gelöscht wird die geprüfte Durchführung dieser Seite, nicht eine
    // beliebige ID aus dem Formular.
    TestDurchfuehrung::delete((int) $durchfuehrung['id'], (int) $dog['id']);
    header('Location: /dog_detail.php?id=' . (int) $dog['id']);
    exit;
}

$statusLabels    = ['offen' => t('status.offen'), 'bestanden' => t('status.bestanden'), 'nicht_bestanden' => t('status.nicht_bestanden')];
$statusFarben    = ['offen' => 'bg-sand/50 text-fellDk/70', 'bestanden' => 'bg-tanne/10 text-tanneDk', 'nicht_bestanden' => 'bg-red-50 text-red-700'];
$kategorieLabels = ['bestanden' => t('status.bestanden'), 'neutral' => t('status.neutral'), 'nicht_bestanden' => t('status.nicht_bestanden')];
$kategorieFarben = ['bestanden' => 'bg-tanne/10 text-tanneDk', 'neutral' => 'bg-pfote/20 text-fellDk/70', 'nicht_bestanden' => 'bg-red-50 text-red-700'];

$pageTitle = $durchfuehrung['test_name'];
require __DIR__ . '/views/partials/header.php';
?>

<a href="/dog_detail.php?id=<?= (int) $dog['id'] ?>" class="inline-flex items-center gap-1.5 text-sm text-fellDk/60 hover:text-fellDk mb-4">
    ← <?= e(t('td.zurueck_zu')) ?> <?= e($dog['name']) ?>
</a>

<div class="bg-white/80 rounded-2xl shadow border border-sand p-4 sm:p-6 max-w-2xl">
    <div class="flex flex-wrap items-start justify-between gap-2">
        <div>
            <h1 class="text-2xl font-extrabold text-fellDk break-words"><?= e($durchfuehrung['test_name']) ?></h1>
            <p class="text-sm text-fellDk/60 mt-1">
                <?= e(t('td.durchgefuehrt_am')) ?> <?= e(date('d.m.Y', strtotime($durchfuehrung['durchfuehrungsdatum']))) ?> <?= e(t('td.bei')) ?> <?= e($dog['name']) ?>
            </p>
        </div>
        <div class="flex gap-2 shrink-0">
            <a href="/testdurchfuehrung_form.php?id=<?= (int) $durchfuehrung['id'] ?>" class="bg-pfote/10 text-fell font-semibold text-sm px-4 py-2 rounded-full hover:bg-pfote/20 transition-colors whitespace-nowrap">
                <?= e(t('allg.bearbeiten')) ?>
            </a>
            <form method="post" action="/testdurchfuehrung_detail.php?id=<?= (int) $durchfuehrung['id'] ?>" onsubmit="return confirm(<?= e(json_encode(t('td.loeschen_frage'), JSON_UNESCAPED_UNICODE)) ?>);">
                <?= csrf_field() ?>
                <input type="hidden" name="delete_durchfuehrung_id" value="<?= (int) $durchfuehrung['id'] ?>">
                <button type="submit" class="bg-red-50 text-red-700 font-semibold text-sm px-4 py-2 rounded-full hover:bg-red-100 transition-colors whitespace-nowrap">
                    <?= e(t('allg.loeschen')) ?>
                </button>
            </form>
        </div>
    </div>

    <div class="mt-4">
        <span class="inline-block text-sm font-semibold px-3 py-1 rounded-full <?= $statusFarben[$durchfuehrung['status']] ?? 'bg-sand/50 text-fellDk/70' ?>">
            <?= e(t('td.gesamtstatus')) ?> <?= e($statusLabels[$durchfuehrung['status']] ?? $durchfuehrung['status']) ?>
        </span>
    </div>

    <?php if (!empty($durchfuehrung['notizen'])): ?>
        <div class="mt-4">
            <h2 class="font-bold text-fellDk text-sm mb-1"><?= e(t('allg.notizen')) ?></h2>
            <p class="text-sm text-fellDk/70 whitespace-pre-line"><?= e($durchfuehrung['notizen']) ?></p>
        </div>
    <?php endif; ?>

    <div class="mt-6">
        <h2 class="font-bold text-fellDk mb-3"><?= e(t('td.aufgaben_ergebnisse')) ?></h2>
        <?php if (empty($durchfuehrung['ergebnisse'])): ?>
            <p class="text-sm text-fellDk/50"><?= e(t('td.keine_ergebnisse')) ?></p>
        <?php else: ?>
            <div class="space-y-3">
                <?php foreach ($durchfuehrung['ergebnisse'] as $ergebnis): ?>
                    <div class="border border-sand rounded-lg p-4">
                        <div class="flex flex-wrap items-center justify-between gap-2">
                            <p class="font-semibold text-sm"><?= e($ergebnis['aufgabe_titel']) ?></p>
                            <span class="text-xs font-semibold px-2.5 py-1 rounded-full <?= $kategorieFarben[$ergebnis['kategorie']] ?? 'bg-sand/50 text-fellDk/70' ?>">
                                <?= e($kategorieLabels[$ergebnis['kategorie']] ?? $ergebnis['kategorie']) ?>
                            </span>
                        </div>
                        <p class="text-sm text-fellDk/70 mt-1"><?= e($ergebnis['ergebnis_bezeichnung']) ?></p>
                        <?php if (!empty($ergebnis['notizen'])): ?>
                            <p class="text-xs text-fellDk/50 mt-2 whitespace-pre-line"><?= e(t('td.notiz')) ?> <?= e($ergebnis['notizen']) ?></p>
                        <?php endif; ?>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require __DIR__ . '/views/partials/footer.php'; ?>
